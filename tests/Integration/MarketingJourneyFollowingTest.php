<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\Journey;
use CattoPHP\Marketing\Journey\JourneyRecorded;
use CattoPHP\Marketing\JourneyEvent;
use CattoPHP\Marketing\Landing\LandingView;
use CattoPHP\Marketing\Landing\LandingVisit;
use CattoPHP\Marketing\Offer;
use CattoPHP\Marketing\PublicationInput;
use CattoPHP\Marketing\TouchAgent;
use CattoPHP\Marketing\Visitors;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\MarketingHarness;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The journey of step 3 against the database: only a followed reader's actions are recorded (a person, not asking not to
 * be tracked, whose cookie names a visitor or whose account a visitor is tied to), each with their current campaign
 * (the latest counted click within the sale's window); repeats within the same visit are not recorded again; a
 * registration ties both browsers' visitors to the account and is recorded once; a sign-in never takes another
 * person's visitor; step 2's visit and press name no account for a browser asking not to be tracked; and a failure
 * never breaks the page.
 */
final class MarketingJourneyFollowingTest extends DatabaseTestCase
{
    private const COMPUTER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:146.0) Gecko/20100101 Firefox/146.0';
    private const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';

    private MarketingHarness $marketing;
    private TokenStorage $tokens;
    private Offer $tea;
    private Offer $mug;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new TokenStorage();
        $this->marketing = new MarketingHarness($this->connection, ['APP_TIMEZONE' => 'Africa/Johannesburg'], tokens: $this->tokens);
        $this->marketing->campaign('Spring sale', 'spring-sale', destination: '/shop');
        $this->marketing->publication('spring-sale', 'facebook', 'Cape Town readers');
        $this->marketing->campaign('Winter warmers', 'winter-warmers', destination: '/shop');
        $this->marketing->publication('winter-warmers', 'email', 'News and updates');
        $this->tea = new Offer('product', 'tea-100', 'Rooibos tea, 100 bags');
        $this->mug = new Offer('product', 'mug-01', 'Enamel mug');
    }

    protected function tearDown(): void
    {
        $this->marketing->remove();
        parent::tearDown();
    }

    public function testOnlyAFollowedReadersViewIsRecordedOnceInAVisitWithTheirCampaign(): void
    {
        $journey = $this->marketing->journey;
        $journey->viewed($this->tea, $this->request());
        self::assertSame([], $this->events(), 'A reader who never came by a campaign leaves nothing.');

        [$token, $touch] = $this->arrive('spring-sale', 'fb1');
        $journey->viewed($this->tea, $this->request($token));
        $event = $this->only();
        $visitor = $this->marketing->visitors->byToken($token);
        $campaign = $this->marketing->campaigns->get('spring-sale');
        self::assertSame([Events::OFFER_VIEWED, $visitor?->id, null, $campaign?->id, $touch, 'product', 'tea-100', 'ZA', false],
            [$event->kind, $event->visitorId, $event->account, $event->campaignId, $event->touchId, $event->offerType, $event->offerReference, $event->country, $event->doNotTrack]);
        self::assertNotNull($event->publicationId);
        $recorded = $this->marketing->dispatcher->of(JourneyRecorded::class);
        self::assertCount(1, $recorded);
        self::assertSame([$event->id, 'Rooibos tea, 100 bags'], [$recorded[0]->event->id, $recorded[0]->offer?->label], 'Announced with the offer as the project described it.');

        $journey->viewed($this->tea, $this->request($token));
        self::assertCount(1, $this->events(), 'The same offer again within the same visit.');
        $journey->viewed($this->mug, $this->request($token));
        self::assertCount(2, $this->events(), 'Another offer is another view.');
        $this->marketing->site->clock->modify('+31 minutes');
        $journey->viewed($this->tea, $this->request($token));
        self::assertCount(3, $this->events(), 'After the same visit has passed.');
    }

    public function testBotsPreviewsAndBrowsersAskingNotToBeTrackedAreNotFollowed(): void
    {
        [$token] = $this->arrive('spring-sale', 'fb1');
        $journey = $this->marketing->journey;
        $journey->viewed($this->tea, $this->request($token, 'Googlebot/2.1 (+http://www.google.com/bot.html)'));
        $journey->viewed($this->tea, $this->request($token, 'WhatsApp/2.24.1.6 A'));
        $journey->viewed($this->tea, $this->request($token, server: ['HTTP_DNT' => '1']));
        $journey->viewed($this->tea, $this->request($token, server: ['HTTP_SEC_GPC' => '1']));
        self::assertSame([], $this->events(), 'A search engine, a link preview and a browser asking not to be tracked.');
        self::assertNull($journey->carry($this->request($token, server: ['HTTP_DNT' => '1'])));

        $ignoring = new MarketingHarness($this->connection, ['MARKETING_HONOUR_DO_NOT_TRACK' => 'false']);
        try {
            $ignoring->journey->viewed($this->tea, $this->request($token, server: ['HTTP_DNT' => '1']));
            self::assertCount(1, $this->events(), 'With the setting off, the request is followed as any other.');
            self::assertFalse($this->events()[0]->doNotTrack);
        } finally {
            $ignoring->remove();
        }

        $visitor = $this->marketing->visitors->byToken($token) ?? throw new \LogicException('No visitor.');
        $this->marketing->visitors->anonymise($visitor, $this->marketing->campaigns->now());
        $this->marketing->site->clock->modify('+1 hour');
        $journey->viewed($this->tea, $this->request($token));
        self::assertCount(1, $this->events(), 'A visitor made anonymous is followed no more.');
    }

    public function testEmailLinkScannersAreBotsEvenWhenTheyReadAsABrowser(): void
    {
        foreach (['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36 Mimecast', 'Barracuda Sentinel (EE)',
            'Mozilla/5.0 (compatible; Proofpoint URL Defense)', 'Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)',
            'Microsoft Office Existence Discovery'] as $scanner) {
            self::assertSame(TouchAgent::BOT, TouchAgent::read($scanner)->kind, $scanner);
        }
        self::assertSame([TouchAgent::PERSON, 'phone'], [TouchAgent::read(self::PHONE)->kind, TouchAgent::read(self::PHONE)->device]);
    }

    public function testASignedInReaderIsFollowedByTheirAccountWithoutTheCookie(): void
    {
        [$token, $touch] = $this->arrive('spring-sale', 'fb1');
        $visitor = $this->marketing->visitors->byToken($token) ?? throw new \LogicException('No visitor.');
        $this->marketing->visitors->identify($visitor->id, '7');
        $this->signIn('7');
        $this->marketing->journey->viewed($this->tea, $this->request(agent: self::PHONE));
        $event = $this->only();
        self::assertSame([null, '7', $touch], [$event->visitorId, $event->account, $event->touchId], 'Another browser of theirs, counted for their campaign.');

        $this->signIn('8');
        $this->marketing->journey->viewed($this->mug, $this->request());
        self::assertCount(1, $this->events(), 'A signed-in person who never came by a campaign leaves nothing.');
        $this->tokens->setToken(null);
        $this->marketing->journey->viewed($this->mug, $this->request(), '7');
        self::assertSame('7', $this->events()[0]->account, 'The account may be given by a service with no signed-in reader.');
    }

    public function testFavouritesTheBasketAFreeOfferAndASubscriptionEachKeepTheirRule(): void
    {
        [$token] = $this->arrive('spring-sale', 'fb1');
        $journey = $this->marketing->journey;
        $journey->favourited($this->tea, true, $this->request($token));
        $journey->favourited($this->tea, true, $this->request($token));
        $journey->favourited($this->tea, false, $this->request($token));
        $journey->favourited($this->tea, true, $this->request($token));
        self::assertSame([Events::FAVOURITED, Events::UNFAVOURITED, Events::FAVOURITED], $this->kinds(), 'A repeat of the same state within the visit is not recorded.');

        $this->clear();
        $journey->basketChanged($this->tea, true, 2, $this->request($token));
        $journey->basketChanged($this->tea, true, 1, $this->request($token));
        $journey->basketChanged($this->tea, false, 0, $this->request($token));
        self::assertSame([Events::BASKET_ADDED, Events::BASKET_ADDED, Events::BASKET_REMOVED], $this->kinds(), 'Each change a person made.');
        self::assertSame([2, 1, 1], array_map(static fn(JourneyEvent $e): mixed => $e->detail['quantity'] ?? null, array_reverse($this->events())));

        $this->clear();
        $journey->taken($this->mug, $this->request($token));
        $journey->taken($this->mug, $this->request($token));
        $journey->subscribed("  News   and\nupdates ", $this->request($token));
        $journey->subscribed('News and updates', $this->request($token));
        $journey->subscribed(str_repeat('Long list ', 20), $this->request($token));
        $journey->subscribed('   ', $this->request($token));
        self::assertSame([Events::TAKEN, Events::SUBSCRIBED, Events::SUBSCRIBED], $this->kinds(), 'Taken once and each list once, however often reported; an empty label is nothing.');
        $visitor = $this->marketing->visitors->byToken($token);
        self::assertSame('taken:v' . $visitor?->id . ':product:mug-01', $this->events()[2]->idempotencyKey);
        self::assertSame(['News and updates', trim(str_repeat('Long list ', 8))], [$this->events()[1]->detail['list'] ?? null, $this->events()[0]->detail['list'] ?? null], 'At most 80 characters.');
    }

    public function testARegistrationTiesBothBrowsersVisitorsAndIsRecordedOnce(): void
    {
        $journey = $this->marketing->journey;
        [$phone] = $this->arrive('spring-sale', 'fb1', self::PHONE);
        $carried = $journey->carry($this->request($phone, self::PHONE));
        $phoneVisitor = $this->marketing->visitors->byToken($phone);
        self::assertSame($phoneVisitor?->reference, $carried, 'The form was filled in on the phone.');
        self::assertNull($journey->carry($this->request()), 'Nothing to carry for a reader who is not followed.');

        $this->marketing->site->clock->modify('+2 hours');
        [$computer, $computerTouch] = $this->arrive('winter-warmers', 'em1');
        $journey->registered('21', $carried, $this->request($computer));
        $journey->registered('21', $carried, $this->request($computer));
        $event = $this->only();
        $computerVisitor = $this->marketing->visitors->byToken($computer);
        self::assertSame([Events::REGISTERED, 'registered:21', '21', $computerVisitor?->id, $computerTouch, $this->marketing->campaigns->get('winter-warmers')?->id],
            [$event->kind, $event->idempotencyKey, $event->account, $event->visitorId, $event->touchId, $event->campaignId], 'Once, with the latest counted click of either browser.');
        self::assertSame(['21', '21'], [$this->marketing->visitors->byToken($phone)?->account, $this->marketing->visitors->byToken($computer)?->account], 'Both browsers are the person now.');

        $journey->registered('22', $carried, $this->request());
        self::assertSame('21', $this->marketing->visitors->byToken($phone)?->account, 'A visitor keeps the first account it was tied to.');
        $journey->registered('23', null, $this->request());
        self::assertCount(1, $this->events(), 'A registration no campaign touched is not a marketing event.');

        [$private] = $this->arrive('spring-sale', null);
        $journey->registered('24', $this->marketing->visitors->byToken($private)?->reference, $this->request($private, server: ['HTTP_DNT' => '1']));
        self::assertNull($this->marketing->visitors->byToken($private)?->account, 'A browser asking not to be tracked is never tied.');
        self::assertCount(1, $this->events());
        self::assertSame([Events::REGISTERED], array_map(static fn(JourneyRecorded $r): string => $r->event->kind, $this->marketing->dispatcher->of(JourneyRecorded::class)));
    }

    public function testASignInTiesTheBrowserOnlyWhenItHasNobodyAndIsRecordedOnceInAVisit(): void
    {
        $journey = $this->marketing->journey;
        [$token] = $this->arrive('spring-sale', 'fb1');
        $visitor = $this->marketing->visitors->byToken($token);
        $journey->signedIn('31', $this->request($token));
        $journey->signedIn('31', $this->request($token));
        self::assertSame('31', $this->marketing->visitors->byToken($token)?->account);
        self::assertSame([[Events::SIGNED_IN, '31', $visitor?->id]], array_map(static fn(JourneyEvent $e): array => [$e->kind, $e->account, $e->visitorId], $this->events()));

        $journey->signedIn('32', $this->request($token));
        self::assertSame('31', $this->marketing->visitors->byToken($token)?->account, 'A browser two people use keeps its first person.');
        self::assertSame([Events::SIGNED_IN, '32', $visitor?->id], [$this->events()[0]->kind, $this->events()[0]->account, $this->events()[0]->visitorId],
            'The second person\'s sign-in carries their own account.');
        $this->marketing->site->clock->modify('+31 minutes');
        $journey->signedIn('31', $this->request($token));
        self::assertCount(3, $this->events(), 'Again after the visit has passed.');
        $journey->signedIn('33', $this->request());
        self::assertCount(3, $this->events(), 'A sign-in no campaign touched leaves nothing.');
    }

    public function testComingBackIsRecordedAfterAQuietVisitAndMovesLastSeen(): void
    {
        [$token] = $this->arrive('spring-sale', 'fb1');
        $journey = $this->marketing->journey;
        $clock = $this->marketing->site->clock;
        $journey->cameBack($this->request($token));
        self::assertSame([], $this->events(), 'Within the visit of the click.');
        $clock->modify('+20 minutes');
        $journey->cameBack($this->request($token));
        $clock->modify('+20 minutes');
        $journey->cameBack($this->request($token));
        self::assertSame([], $this->events(), 'Reading on, page after page, is the same visit.');
        self::assertEquals($clock->now(), $this->marketing->visitors->byToken($token)?->lastSeenAt, 'Each page moves the visitor\'s last seen on.');

        $clock->modify('+3 days');
        $journey->cameBack($this->request($token, server: ['HTTP_REFERER' => 'https://search.example.org/?q=tea']));
        $clock->modify('+2 days');
        $journey->cameBack($this->request($token));
        self::assertSame([['came_back', 'typed'], ['came_back', 'search.example.org']], array_map(static fn(JourneyEvent $e): array => [$e->kind, $e->detail['from'] ?? null], $this->events()));
        self::assertSame([$this->marketing->campaigns->get('spring-sale')?->id, $this->marketing->campaigns->get('spring-sale')?->id],
            [$this->events()[0]->campaignId, $this->events()[1]->campaignId], 'Both within the sale\'s window of the click.');
    }

    public function testTheCurrentCampaignIsTheLatestCountedClickWithinTheSalesWindow(): void
    {
        [$token] = $this->arrive('spring-sale', 'fb1');
        $this->marketing->site->clock->modify('+1 day');
        $this->arrive('winter-warmers', 'em1', token: $token);
        $this->marketing->campaigns->pause('winter-warmers');
        $this->marketing->site->clock->modify('+1 hour');
        $this->arrive('winter-warmers', 'em1', token: $token);
        $this->marketing->journey->viewed($this->tea, $this->request($token));
        $winter = $this->marketing->campaigns->get('winter-warmers');
        self::assertSame($winter?->id, $this->only()->campaignId, 'A click while the campaign was paused does not count, the one before it does.');

        $this->marketing->site->clock->modify('+31 days');
        $this->marketing->journey->viewed($this->tea, $this->request($token));
        self::assertNull($this->events()[0]->campaignId, 'Past the window, the reader is still followed but no campaign is theirs.');
    }

    public function testStepTwosVisitAndPressNameNoAccountForABrowserAskingNotToBeTracked(): void
    {
        $page = $this->marketing->pages->createForCampaign('spring-sale')->page;
        $this->signIn('41');
        $view = new LandingView($page, $this->marketing->pages->context($page), new LandingVisit(null, false, '/c/spring-sale', $page->reference), '/c/spring-sale', null, []);
        $this->marketing->journey->opened($view, $this->request(server: ['HTTP_DNT' => '1']));
        $this->marketing->journey->pressed($page, $this->tea, 'buy', $this->request(server: ['HTTP_DNT' => '1']));
        $this->marketing->journey->opened($view, $this->request());
        self::assertSame([[Events::LANDING_OPENED, '41', false], [Events::LANDING_PRESSED, null, true], [Events::LANDING_OPENED, null, true]],
            array_map(static fn(JourneyEvent $e): array => [$e->kind, $e->account, $e->doNotTrack], $this->events()), 'Counted, naming nobody.');
    }

    public function testTheCurrentRequestIsReadWhenNoneIsGivenAndAFailureNeverBreaksThePage(): void
    {
        [$token] = $this->arrive('spring-sale', 'fb1');
        $requests = new RequestStack();
        $requests->push($this->request($token));
        $m = $this->marketing;
        $journey = new Journey($m->events, $m->visitors, $m->settings, MarketingHarness::network(), $this->tokens, $m->dispatcher, $m->logger, $m->site->clock, $m->readers, $requests);
        $journey->viewed($this->tea);
        self::assertSame($m->visitors->byToken($token)?->reference, $journey->carry());
        self::assertCount(1, $this->events(), 'The current request\'s reader.');
        $without = new Journey($m->events, $m->visitors, $m->settings, MarketingHarness::network(), null, null, $m->logger, $m->site->clock, $m->readers);
        $without->viewed($this->mug);
        $without->registered('51', 'nobody00');
        self::assertCount(1, $this->events(), 'Without any request, no browser is followed.');
        $unwired = new Journey($m->events, $m->visitors, $m->settings, MarketingHarness::network(), null, null, $m->logger, $m->site->clock);
        $unwired->viewed($this->tea, $this->request($token));
        self::assertContains('error: An offer\'s view could not be recorded in the visitor\'s journey; the page went on all the same.', $m->logger->lines, 'A journey given no readers says so.');

        $this->connection->executeStatement('DROP TABLE cattophp_marketing_events CASCADE');
        $m->site->clock->modify('+1 hour');
        $m->journey->viewed($this->tea, $this->request($token));
        $m->journey->signedIn('52', $this->request($token));
        self::assertContains('error: A sign-in could not be recorded in the visitor\'s journey; the page went on all the same.', $m->logger->lines);
    }

    /**
     * A person arriving by a tracking link (a new visitor, or the one the token names): their cookie's token and the touch.
     *
     * @return array{0:string,1:int}
     */
    private function arrive(string $campaign, ?string $publication, string $agent = self::COMPUTER, ?string $token = null): array
    {
        $request = $this->request($token, $agent, path: '/c/' . $campaign . ($publication === null ? '' : '/' . $publication));
        $opening = $this->marketing->links->open($campaign, $publication, $request) ?? throw new \LogicException('No such link.');
        $tracked = $this->marketing->links->recordOpening($opening, $request);

        return [(string) ($tracked->cookie?->getValue() ?? $token), $tracked->touchId];
    }

    /** @param array<string,string> $server */
    private function request(?string $token = null, string $agent = self::COMPUTER, array $server = [], string $path = '/shop/tea-100'): Request
    {
        return Request::create($path, 'GET', [], $token === null ? [] : [Visitors::COOKIE => $token], [], $server + ['HTTP_USER_AGENT' => $agent, 'REMOTE_ADDR' => '198.51.100.7']);
    }

    private function signIn(string $account): void
    {
        $this->tokens->setToken(new UsernamePasswordToken(new InMemoryUser($account, null), 'main'));
    }

    private function clear(): void
    {
        $this->connection->executeStatement('DELETE FROM cattophp_marketing_events');
    }

    /** @return list<string> the events' kinds, oldest first */
    private function kinds(): array
    {
        return array_map(static fn(JourneyEvent $e): string => $e->kind, array_reverse($this->events()));
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
