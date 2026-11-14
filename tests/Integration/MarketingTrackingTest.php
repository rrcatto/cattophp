<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Account\Records\MarketingRecords;
use CattoPHP\Marketing\MarketingTidyUpTask;
use CattoPHP\Marketing\PublicationInput;
use CattoPHP\Marketing\Touch;
use CattoPHP\Marketing\TrackingLinks;
use CattoPHP\Marketing\Visitors;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\MarketingHarness;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * The tracking links as services: their three forms, where they lead with the query carried, and what an opening records:
 * a person's visitor and cookie, a repeat within one visit, a link preview set aside, a browser that asks not to be
 * tracked, a campaign not running; and the nightly tidy-up and forgetting an account.
 */
final class MarketingTrackingTest extends DatabaseTestCase
{
    private const PERSON = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:146.0) Gecko/20100101 Firefox/146.0';
    private const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';

    private MarketingHarness $marketing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marketing = new MarketingHarness($this->connection);
    }

    protected function tearDown(): void
    {
        $this->marketing->remove();
        parent::tearDown();
    }

    public function testALinkComesInThreeFormsThatEachNameThePublication(): void
    {
        $campaign = $this->marketing->campaign('Spring sale', 'spring-sale');
        $publication = $this->marketing->publication('spring-sale', 'search-adverts');
        $forms = $this->marketing->links->forms($campaign, $publication);
        self::assertSame('https://example.com/c/spring-sale/sa1', $forms->short);
        self::assertSame('https://example.com/c/spring-sale?src=sa1', $forms->source);
        self::assertSame('https://example.com/c/spring-sale?utm_source=search-adverts&utm_medium=search-adverts&utm_campaign=spring-sale&utm_content=sa1', $forms->utm);
        self::assertSame(['https://example.com/c/spring-sale', '/c/spring-sale/sa1', 'c'], [$this->marketing->links->campaignLink($campaign), $this->marketing->links->path($campaign, $publication),
            $this->marketing->links->prefix()]);
        foreach ([$forms->short, $forms->source, $forms->utm] as $link) {
            $path = trim((string) parse_url($link, PHP_URL_PATH), '/');
            $parts = explode('/', $path);
            $opening = $this->marketing->links->open($parts[1], $parts[2] ?? null, Request::create($link));
            self::assertSame('sa1', $opening?->publication?->code, $link);
            self::assertSame('search-adverts', $opening->source);
        }
    }

    public function testALinkLeadsWhereItWasToldCarryingTheQueryItDoesNotKnow(): void
    {
        $this->marketing->campaign('Spring sale', 'spring-sale', destination: '/shop?from=spring#top');
        $this->marketing->publications->create('spring-sale', new PublicationInput('facebook', destination: 'https://shop.example.org/tea'));
        $this->marketing->publication('spring-sale', 'email');
        $open = fn(string $uri, ?string $publication = null): ?string => $this->marketing->links->open('spring-sale', $publication, Request::create($uri))?->address;
        self::assertSame('https://shop.example.org/tea?colour=red', $open('/c/spring-sale/fb1?colour=red&fbclid=abc&utm_source=facebook', 'fb1'), 'A publication\'s own destination.');
        self::assertSame('/shop?from=spring&size=large#top', $open('/c/spring-sale/em1?size=large&from=elsewhere&gclid=x', 'em1'), 'The campaign\'s, its own values winning.');
        self::assertSame('/shop?from=spring#top', $open('/c/spring-sale?src=newsletter'));
        self::assertSame('/shop?from=spring&tags%5B0%5D=a&tags%5B1%5D=b#top', $open('/c/spring-sale?tags[]=a&tags[]=b'));
        self::assertNull($open('/c/spring-sale/zz9', 'zz9'), 'An unknown publication is not found.');
        self::assertNull($this->marketing->links->open('no-such-campaign', null, Request::create('/c/no-such-campaign')));
        self::assertSame('fb1', $this->marketing->links->open('SPRING-SALE', 'FB1', Request::create('/c/SPRING-SALE/FB1'))?->publication?->code, 'Codes are read in any case.');
        self::assertSame('/', $this->marketing->links->forwardAddress('javascript:alert(1)', Request::create('/c/x')), 'Never anywhere nobody chose.');
    }

    public function testAPersonIsGivenAVisitorAndACookieAndARepeatWithinOneVisitIsNotAnotherClick(): void
    {
        $this->marketing->campaign('Spring sale', 'spring-sale');
        $this->marketing->publication('spring-sale', 'facebook', 'Cape Town readers');
        $clock = $this->marketing->site->clock;
        $cookie = $this->record('/c/spring-sale/fb1', 'fb1', self::PHONE, server: ['HTTP_REFERER' => 'https://m.social.example.com/feed', 'HTTPS' => 'on']);
        self::assertNotNull($cookie);
        self::assertSame([Visitors::COOKIE, true, true, Cookie::SAMESITE_LAX, '/'], [$cookie->getName(), $cookie->isHttpOnly(), $cookie->isSecure(), $cookie->getSameSite(), $cookie->getPath()]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $cookie->getValue());
        self::assertSame($clock->now()->modify('+30 days')->getTimestamp(), $cookie->getExpiresTime());
        $visitor = $this->marketing->visitors->byToken((string) $cookie->getValue());
        self::assertSame(['ZA', 'phone', null], [$visitor?->country, $visitor?->device, $visitor?->account]);
        $clock->modify('+10 minutes');
        $again = $this->record('/c/spring-sale/fb1', 'fb1', self::PHONE, (string) $cookie->getValue());
        self::assertSame($cookie->getValue(), $again?->getValue(), 'The same visitor, their cookie\'s life started again.');
        self::assertSame($clock->now()->modify('+30 days')->getTimestamp(), $again?->getExpiresTime());
        $clock->modify('+1 hour');
        $this->record('/c/spring-sale/fb1', 'fb1', self::PHONE, (string) $cookie->getValue());
        $this->record('/c/spring-sale', null, self::PHONE, (string) $cookie->getValue());
        $touches = $this->marketing->touches->forVisitor((int) $visitor?->id);
        self::assertSame([
            [true, null, true, 'facebook', 'm.social.example.com'],
            [false, 'same_visit', false, 'facebook', null],
            [true, null, false, 'facebook', null],
            [true, null, false, '', null],
        ], array_map(static fn(Touch $t): array => [$t->counted, $t->notCountedBecause, $t->firstForVisitor, $t->source, $t->referrerHost], $touches), 'Oldest first: the first click, a repeat within the visit, a later visit, the campaign\'s own link.');
        self::assertSame(['198.51.100.7', 'ZA', 'person', '/'], [$touches[0]->ip, $touches[0]->country, $touches[0]->agent, $touches[0]->landing]);
        self::assertSame(1, $this->marketing->visitors->count());
    }

    public function testALinkPreviewIsRecordedAndSetAsideWithNoVisitor(): void
    {
        $campaign = $this->marketing->campaign('Spring sale', 'spring-sale');
        self::assertNull($this->record('/c/spring-sale', null, 'WhatsApp/2.24.1.6 A'));
        self::assertNull($this->record('/c/spring-sale', null, 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'));
        self::assertNull($this->record('/c/spring-sale', null, 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'));
        $touches = $this->marketing->touches->forCampaign($campaign->id);
        self::assertSame([['search', false, 'search'], ['preview', false, 'preview'], ['preview', false, 'preview']],
            array_map(static fn(Touch $t): array => [$t->agent, $t->counted, $t->notCountedBecause], $touches));
        self::assertSame([null, null, null], array_map(static fn(Touch $t): ?int => $t->visitorId, $touches));
        self::assertSame(0, $this->marketing->visitors->count());
    }

    public function testABrowserAskingNotToBeTrackedIsCountedWithItsCountryAndNothingElse(): void
    {
        $campaign = $this->marketing->campaign('Spring sale', 'spring-sale');
        self::assertNull($this->record('/c/spring-sale', null, self::PERSON, server: ['HTTP_DNT' => '1']));
        self::assertNull($this->record('/c/spring-sale', null, self::PERSON, server: ['HTTP_SEC_GPC' => '1', 'REMOTE_ADDR' => '203.0.113.4']));
        $touches = $this->marketing->touches->forCampaign($campaign->id);
        self::assertSame([[true, null, null, 'GB', true], [true, null, null, 'ZA', true]],
            array_map(static fn(Touch $t): array => [$t->counted, $t->visitorId, $t->ip, $t->country, $t->doNotTrack], $touches));
        self::assertSame(0, $this->marketing->visitors->count());

        $tracked = new MarketingHarness($this->connection, ['MARKETING_HONOUR_DO_NOT_TRACK' => 'false']);
        try {
            $request = Request::create('/c/spring-sale', server: ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_USER_AGENT' => self::PERSON, 'HTTP_DNT' => '1']);
            $opening = $tracked->links->open('spring-sale', null, $request);
            self::assertNotNull($opening);
            self::assertNotNull($tracked->links->record($opening, $request), 'With the setting off, the browser is followed as any other.');
        } finally {
            $tracked->remove();
        }
    }

    public function testACampaignNotRunningStillForwardsAndItsClicksAreSetAsideWithWhy(): void
    {
        $marketing = $this->marketing;
        foreach (['draft' => false, 'paused' => true, 'archived' => true] as $code => $active) {
            $marketing->campaign(ucfirst($code), $code, active: $active);
        }
        $marketing->campaigns->pause('paused');
        $marketing->campaigns->archive('archived');
        $marketing->campaigns->create(new \CattoPHP\Marketing\CampaignInput('Later', 'later', startsAt: '2026-12-01'));
        $marketing->campaigns->activate('later');
        $marketing->campaigns->create(new \CattoPHP\Marketing\CampaignInput('Over', 'over', startsAt: '2026-10-01', endsAt: '2026-10-31'));
        $marketing->campaigns->activate('over');
        foreach (['draft', 'paused', 'archived', 'later', 'over'] as $code) {
            self::assertNotNull($this->record('/c/' . $code, null, self::PERSON), 'A person still gets a visitor: ' . $code);
            $touch = $marketing->touches->forCampaign($marketing->campaigns->require($code)->id)[0];
            self::assertSame([false, ['later' => 'not_started', 'over' => 'ended'][$code] ?? $code], [$touch->counted, $touch->notCountedBecause], $code);
        }
    }

    public function testTheTidyUpRemovesOldAddressesAndMakesOldVisitorsAnonymousAndForgettingKeepsTheTotals(): void
    {
        $marketing = $this->marketing;
        $campaign = $marketing->campaign('Spring sale', 'spring-sale');
        $cookie = $this->record('/c/spring-sale', null, self::PERSON);
        $visitor = $marketing->visitors->byToken((string) $cookie?->getValue());
        self::assertNotNull($visitor);
        $marketing->visitors->identify($visitor->id, '7');
        $this->connection->executeStatement("UPDATE cattophp_marketing_campaigns SET created_by = '7'");
        $records = new MarketingRecords($marketing->visitors, $this->connection);
        self::assertSame(['removed' => [], 'unnamed' => ['campaign visitors' => 1, 'campaign records made' => 1]], $records->forgetAccount('7', null));
        self::assertNull($marketing->visitors->byId($visitor->id)?->account);
        self::assertNull($marketing->campaigns->require('spring-sale')->createdBy);

        $task = new MarketingTidyUpTask($marketing->touches, $marketing->visitors, $marketing->settings);
        self::assertSame(['marketing_tidy_up', 86400, '03:20'], [$task->key(), $task->interval(), $task->runsAt()]);
        $now = $marketing->site->clock->now();
        self::assertSame('0 IP addresses removed, 0 visitors made anonymous.', $task->run($now));
        self::assertSame('1 IP address removed, 0 visitors made anonymous.', $task->run($now->modify('+31 days')));
        self::assertNull($marketing->touches->forCampaign($campaign->id)[0]->ip);
        self::assertSame('ZA', $marketing->touches->forCampaign($campaign->id)[0]->country, 'Its country stays.');
        self::assertSame('0 IP addresses removed, 1 visitor made anonymous.', $task->run($now->modify('+366 days')));
        $anonymous = $marketing->visitors->byId($visitor->id);
        self::assertSame([true, null], [$anonymous?->isAnonymous(), $anonymous?->token]);
        self::assertNull($marketing->visitors->byToken((string) $cookie?->getValue()), 'Its cookie names nobody now.');
        self::assertSame(1, $marketing->figures->totals(\CattoPHP\Marketing\Period::all())->clicks, 'The totals stay.');
    }

    public function testTheLinkPrefixIsSmallLettersDigitsAndHyphens(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TrackingLinks($this->connection, $this->marketing->campaigns, $this->marketing->publications, $this->marketing->channels, $this->marketing->visitors, $this->marketing->touches,
            $this->marketing->settings, MarketingHarness::network(), null, 'Go/');
    }

    /**
     * Opens a link as a browser would and records it: the cookie it was given, or null.
     *
     * @param array<string,string> $server
     */
    private function record(string $uri, ?string $publication, string $agent, ?string $token = null, array $server = []): ?Cookie
    {
        $parts = explode('/', trim((string) parse_url($uri, PHP_URL_PATH), '/'));
        $request = Request::create($uri, 'GET', [], $token === null ? [] : [Visitors::COOKIE => $token], [], $server + ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_USER_AGENT' => $agent]);
        $opening = $this->marketing->links->open($parts[1], $publication, $request);
        self::assertNotNull($opening);

        return $this->marketing->links->record($opening, $request);
    }
}
