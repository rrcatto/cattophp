<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Account\Records\MarketingRecords;
use CattoPHP\Activity\Actor;
use CattoPHP\Marketing\CampaignInput;
use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\JourneyEvent;
use CattoPHP\Marketing\Landing\Events\LandingOfferPressed;
use CattoPHP\Marketing\Landing\Events\LandingPageOpened;
use CattoPHP\Marketing\Landing\LandingPage;
use CattoPHP\Marketing\Landing\LandingPresses;
use CattoPHP\Marketing\Landing\LandingSettingsInput;
use CattoPHP\Marketing\Landing\LandingView;
use CattoPHP\Marketing\Landing\LandingVisit;
use CattoPHP\Marketing\Landing\NotRunning;
use CattoPHP\Marketing\Offer;
use CattoPHP\Marketing\PublicationInput;
use CattoPHP\Marketing\Visitors;
use CattoPHP\Tests\Support\ChangingShop;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\MarketingHarness;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The journey's two events of step 2 against the database: a landing page opened (the visit: a person's only, with the
 * visitor from the cookie or the tracking link opened in the same request, the account signed in, the campaign and
 * publication, the touch and the country; nothing for a preview; no visitor for a browser asking not to be tracked) and
 * an offer's button pressed (only when the press came from a published page that is public now and holds the offer).
 * Each is announced afterwards; a failure while recording, or in a listener, never breaks the page. Forgetting an account
 * reaches the events and the landing pages' records.
 */
final class MarketingJourneyTest extends DatabaseTestCase
{
    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:146.0) Gecko/20100101 Firefox/146.0';

    private MarketingHarness $marketing;
    private TokenStorage $tokens;
    private LandingPage $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new TokenStorage();
        $this->marketing = new MarketingHarness($this->connection, ['APP_TIMEZONE' => 'Africa/Johannesburg'], new ChangingShop(), $this->tokens);
        $this->marketing->campaigns->create(new CampaignInput('Spring sale', 'spring-sale', destination: '/shop', offers: ['product:tea-100', 'bundle:gift-box']));
        $this->marketing->campaigns->activate('spring-sale');
        $this->marketing->publications->create('spring-sale', new PublicationInput('facebook'));
        $this->page = $this->marketing->pages->createForCampaign('spring-sale')->page;
        $faq = array_values(array_filter($this->marketing->sections->list($this->page), static fn($s): bool => $s->kindKey === 'faq'))[0];
        $this->marketing->sections->save($this->page->reference, $faq->reference, ['items' => [['question' => 'When?', 'answer' => 'Now.']]]);
        $this->page = $this->marketing->pages->publish($this->page->reference);
    }

    protected function tearDown(): void
    {
        $this->marketing->remove();
        parent::tearDown();
    }

    public function testAVisitByTheTrackingLinkIsRecordedWithItsTouchVisitorAndPublication(): void
    {
        $request = $this->request('/c/spring-sale/fb1');
        $opening = $this->marketing->links->open('spring-sale', 'fb1', $request) ?? throw new \LogicException('No such link.');
        $tracked = $this->marketing->links->recordOpening($opening, $request);
        self::assertNotNull($tracked->visitorId);
        self::assertSame(['person', 'ZA', false], [$tracked->agent, $tracked->country, $tracked->doNotTrack]);
        self::assertSame(Visitors::COOKIE, $tracked->cookie?->getName());
        $this->marketing->journey->opened($this->view(), $request, $tracked);
        $event = $this->only();
        self::assertSame([Events::LANDING_OPENED, $tracked->visitorId, $tracked->touchId, $tracked->publicationId, $this->page->id, $this->page->campaignId, 'ZA', null],
            [$event->kind, $event->visitorId, $event->touchId, $event->publicationId, $event->landingPageId, $event->campaignId, $event->country, $event->account]);
        $opened = $this->marketing->dispatcher->of(LandingPageOpened::class);
        self::assertCount(1, $opened);
        self::assertTrue($opened[0]->recorded);
        self::assertSame(1, $this->marketing->events->count(Events::LANDING_OPENED, $this->page->id));
        self::assertSame([$this->page->id => 1], $this->marketing->events->visitsByPage($this->marketing->campaigns->now()->modify('-1 day')));
    }

    public function testAVisitWithTheCookieKeepsItsVisitorAndTheSignedInAccountAndAPreviewRecordsNothing(): void
    {
        [$visitor] = $this->marketing->visitors->arrive(null, 'ZA', 'desktop', $this->marketing->campaigns->now());
        $this->tokens->setToken(new UsernamePasswordToken(new InMemoryUser('ayanda@example.com', null), 'main'));
        $this->marketing->journey->opened($this->view(), $this->request('/c/spring-sale', cookies: [Visitors::COOKIE => (string) $visitor->token]));
        $event = $this->only();
        self::assertSame([$visitor->id, 'ayanda@example.com', null, null], [$event->visitorId, $event->account, $event->touchId, $event->publicationId]);

        $this->marketing->journey->opened($this->view(preview: true), $this->request('/marketing/landing-pages/' . $this->page->reference . '/preview'));
        self::assertCount(1, $this->events(), 'A preview records nothing.');
        self::assertCount(1, $this->marketing->dispatcher->of(LandingPageOpened::class), 'and announces nothing.');
    }

    public function testABotsVisitIsNotRecordedAndABrowserAskingNotToBeTrackedKeepsOnlyItsCountry(): void
    {
        $this->marketing->journey->opened($this->view(), $this->request('/c/spring-sale', agent: 'Googlebot/2.1 (+http://www.google.com/bot.html)'));
        self::assertSame([], $this->events());
        self::assertFalse($this->marketing->dispatcher->of(LandingPageOpened::class)[0]->recorded, 'Announced, not recorded.');
        [$visitor] = $this->marketing->visitors->arrive(null, 'GB', 'desktop', $this->marketing->campaigns->now());
        $this->marketing->journey->opened($this->view(), $this->request('/c/spring-sale', cookies: [Visitors::COOKIE => (string) $visitor->token], server: ['HTTP_DNT' => '1', 'REMOTE_ADDR' => '203.0.113.9']));
        $event = $this->only();
        self::assertSame([null, 'GB', true], [$event->visitorId, $event->country, $event->doNotTrack]);
    }

    public function testAPressIsRecordedOnlyFromAPublishedPageThatHoldsTheOffer(): void
    {
        $tea = new Offer('product', 'tea-100', 'Rooibos tea, 100 bags');
        $presses = $this->marketing->presses;
        self::assertTrue($presses->accepted($this->press($this->page->reference), $tea, 'buy'));
        $event = $this->only();
        self::assertSame([Events::LANDING_PRESSED, 'product', 'tea-100', ['action' => 'buy', 'section' => null], $this->page->campaignId],
            [$event->kind, $event->offerType, $event->offerReference, $event->detail, $event->campaignId]);
        $pressed = $this->marketing->dispatcher->of(LandingOfferPressed::class);
        self::assertSame([$this->page->reference, 'buy', 'tea-100'], [$pressed[0]->page->reference, $pressed[0]->action, $pressed[0]->offer?->reference]);

        self::assertFalse($presses->accepted($this->press('zzzzzzzz'), $tea, 'buy'), 'A page that is not there.');
        self::assertFalse($presses->accepted($this->press('<script>'), $tea, 'buy'), 'A forged reference.');
        self::assertFalse($presses->accepted($this->request('/basket/add', 'POST'), $tea, 'buy'), 'No page at all.');
        self::assertFalse($presses->accepted($this->press($this->page->reference), new Offer('product', 'mug-01', 'Enamel mug'), 'buy'), 'An offer the page does not hold.');
        $this->marketing->campaigns->pause('spring-sale');
        self::assertTrue($presses->accepted($this->press($this->page->reference), $tea, 'favourite'), 'A paused campaign\'s page kept as it is still takes presses.');
        $this->marketing->pages->saveSettings($this->page->reference, new LandingSettingsInput(notRunning: NotRunning::FORWARD));
        self::assertFalse($presses->accepted($this->press($this->page->reference), $tea, 'buy'), 'Not from a page visitors cannot see now... ');
        $this->marketing->campaigns->activate('spring-sale');
        $this->marketing->pages->unpublish($this->page->reference);
        self::assertFalse($presses->accepted($this->press($this->page->reference), $tea, 'buy'), '... nor from a page taken down.');
        self::assertCount(2, $this->events());

        $offerPage = $this->marketing->pages->createForOffer('product', 'mug-01')->page;
        $presses->followed($this->request('/landing/enamel-mug/go/x'), $offerPage, 'abcdefgh', null, 'view');
        self::assertCount(2, $this->events(), 'A draft page\'s press is not recorded.');
        self::assertSame('/landing/x', $presses->returnTo($this->press($this->page->reference, '/landing/x')));
        self::assertNull($presses->returnTo($this->press($this->page->reference, 'https://example.com/elsewhere')), 'Never another site.');
        self::assertNull($presses->returnTo($this->press($this->page->reference, '//example.com/elsewhere')));
    }

    public function testAFailureWhileRecordingOrInAListenerNeverBreaksThePage(): void
    {
        $this->marketing->dispatcher->listener = static function (object $event): void {
            throw new \RuntimeException('The listener broke.');
        };
        $this->marketing->journey->opened($this->view(), $this->request('/c/spring-sale'));
        self::assertCount(1, $this->events(), 'Recorded all the same.');
        self::assertContains('error: A listener to the Campaign Marketing Manager\'s LandingPageOpened failed; the page went on all the same.', $this->marketing->logger->lines);
        $this->connection->executeStatement('DROP TABLE cattophp_marketing_events');
        $this->marketing->journey->opened($this->view(), $this->request('/c/spring-sale'));
        $this->marketing->journey->pressed($this->page, null, 'view', $this->request('/c/spring-sale/go/x'));
        self::assertContains('error: A landing page\'s visit could not be recorded; the page was drawn all the same.', $this->marketing->logger->lines);
        self::assertContains('error: A landing page\'s button press could not be recorded; the press went on all the same.', $this->marketing->logger->lines);
    }

    public function testAnEventWithAnIdempotencyKeyIsWrittenOnceAndItsDetailIsKeptSmall(): void
    {
        $events = $this->marketing->events;
        $at = $this->marketing->campaigns->now();
        self::assertNotNull($events->record('registered', $at, idempotencyKey: 'registered:1'));
        self::assertNull($events->record('registered', $at, idempotencyKey: 'registered:1'), 'Reported twice, counted once.');
        $events->record('landing_pressed', $at, detail: ['action' => str_repeat('a', 200), 'nested' => ['not kept'], 'n' => 3]);
        $kept = $this->events()[0]->detail;
        ksort($kept);
        self::assertSame(['action' => str_repeat('a', 80), 'n' => 3], $kept, 'Words cut to 80 characters, anything but words and numbers left out.');
        $this->expectException(\InvalidArgumentException::class);
        $events->record('Not A Kind', $at);
    }

    public function testForgettingAnAccountReachesTheEventsAndTheLandingPagesRecords(): void
    {
        $this->tokens->setToken(new UsernamePasswordToken(new InMemoryUser('7', null), 'main'));
        $this->marketing->journey->opened($this->view(), $this->request('/c/spring-sale'));
        $actor = new Actor('7', 'Thandi Mokoena');
        $page = $this->marketing->pages->createForOffer('product', 'tea-100', null, $actor)->page;
        $this->marketing->sections->hide($page->reference, array_values(array_filter($this->marketing->sections->list($page), static fn($s): bool => $s->kindKey === 'faq'))[0]->reference);
        $this->marketing->pages->publish($page->reference, $actor);
        $this->marketing->pages->saveAsTemplate($page->reference, 'Thandi\'s page', '', $actor);
        $forgotten = (new MarketingRecords($this->marketing->visitors, $this->connection, $this->marketing->events))->forgetAccount('7', null);
        self::assertSame(1, $forgotten['unnamed']['campaign journey events'] ?? null);
        self::assertGreaterThanOrEqual(3, $forgotten['unnamed']['campaign records made'] ?? 0);
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM cattophp_marketing_events WHERE account = '7'"));
        foreach (['cattophp_marketing_landing_pages' => 'created_by', 'cattophp_marketing_landing_versions' => 'made_by', 'cattophp_marketing_landing_templates' => 'made_by'] as $table => $column) {
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . " = '7'"), $table);
        }
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_marketing_landing_versions WHERE landing_page_id = :id', ['id' => $page->id]), 'The version stays.');
    }

    private function view(bool $preview = false): LandingView
    {
        $visit = new LandingVisit(null, $preview, '/c/spring-sale', $this->page->reference);

        return new LandingView($this->page, $this->marketing->pages->context($this->page), $visit, '/c/spring-sale', null, []);
    }

    /**
     * @param array<string,string> $cookies
     * @param array<string,string> $server
     * @param array<string,string> $fields
     */
    private function request(string $path, string $method = 'GET', array $cookies = [], string $agent = self::BROWSER, array $server = [], array $fields = []): Request
    {
        return Request::create($path, $method, $fields, $cookies, [], $server + ['HTTP_USER_AGENT' => $agent, 'REMOTE_ADDR' => '198.51.100.7']);
    }

    private function press(string $page, string $return = '/c/spring-sale'): Request
    {
        return $this->request('/basket/add', 'POST', fields: [LandingPresses::PAGE_FIELD => $page, LandingPresses::RETURN_FIELD => $return]);
    }

    /** @return list<JourneyEvent> every event, newest first */
    private function events(): array
    {
        return $this->marketing->events->recent();
    }

    private function only(): JourneyEvent
    {
        $events = $this->events();
        self::assertCount(1, $events);

        return $events[0];
    }
}
