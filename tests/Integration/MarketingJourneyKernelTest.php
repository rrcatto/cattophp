<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Marketing\CampaignInput;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\Journey;
use CattoPHP\Marketing\Journey\JourneyLine;
use CattoPHP\Marketing\Journey\PersonJourney;
use CattoPHP\Marketing\Journey\PersonJourneys;
use CattoPHP\Marketing\Journey\Readers;
use CattoPHP\Marketing\Journey\VisitorReturns;
use CattoPHP\Marketing\Offer;
use CattoPHP\Marketing\PublicationInput;
use CattoPHP\Marketing\Publications;
use CattoPHP\Marketing\Sales\SaleLine;
use CattoPHP\Marketing\Sales\SaleReport;
use CattoPHP\Marketing\Sales\Sales;
use CattoPHP\Marketing\TrackingLinks;
use CattoPHP\Marketing\Visitor;
use CattoPHP\Marketing\Visitors;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The journey as a project gets it, through the kernel: a followed visitor coming back noticed on the pages they open
 * (only with the cookie, never on an excluded path, an htmx request or within the visit; last_seen_at moved on, the
 * cookie's life untouched), and one person's journey read back with all their visitors merged in time order, the
 * badges from their paid sale's fixed attribution, their state and their figures.
 */
final class MarketingJourneyKernelTest extends DatabaseTestCase
{
    private const COMPUTER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:146.0) Gecko/20100101 Firefox/146.0';
    private const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';
    private const PAGE = ['HTTP_USER_AGENT' => self::COMPUTER, 'HTTP_ACCEPT' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8'];

    private string $folder;
    private TestKernel $kernel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = Foundations::folder();
        $this->kernel = new TestKernel($this->folder, (string) getenv('CATTOPHP_TEST_DATABASE_URL'), marketing: true, marketingConfig: ['returns_excluded' => ['/sign-in']]);
        $this->kernel->boot();
        $campaigns = $this->container()->get(Campaigns::class);
        $publications = $this->container()->get(Publications::class);
        self::assertInstanceOf(Campaigns::class, $campaigns);
        self::assertInstanceOf(Publications::class, $publications);
        foreach (['spring-sale' => ['Spring sale', 'facebook'], 'winter-warmers' => ['Winter warmers', 'email']] as $code => [$name, $channel]) {
            $campaigns->create(new CampaignInput($name, $code, destination: '/open/form'));
            $campaigns->activate($code);
            $publications->create($code, new PublicationInput($channel));
        }
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        Foundations::remove($this->folder);
        restore_exception_handler();
        parent::tearDown();
    }

    public function testAFollowedVisitorComingBackIsNoticedOnThePagesTheyOpen(): void
    {
        self::assertInstanceOf(Readers::class, $this->container()->get(Readers::class));
        self::assertTrue($this->container()->has(VisitorReturns::class), 'On by default.');
        $browser = $this->browser();
        $browser->request('GET', '/c/spring-sale/fb1', server: self::PAGE);
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        $browser->request('GET', '/open/form', server: self::PAGE);
        self::assertSame([200, []], [$browser->getResponse()->getStatusCode(), $browser->getResponse()->headers->getCookies()]);
        self::assertSame([], $this->cameBack(), 'The first page after the click is the same visit.');

        $this->aQuietWeek();
        $before = $this->visitor()->lastSeenAt;
        $browser->request('GET', '/open/form', server: self::PAGE + ['HTTP_REFERER' => 'https://search.example.org/?q=tea']);
        self::assertSame([], $browser->getResponse()->headers->getCookies(), 'The cookie\'s life is restarted only by a tracking link.');
        self::assertSame(['search.example.org'], $this->cameBack());
        self::assertGreaterThan($before, $this->visitor()->lastSeenAt, 'Their last seen moved on.');
        $browser->request('GET', '/open/form', server: self::PAGE);
        self::assertCount(1, $this->cameBack(), 'Not again within the visit.');

        $this->aQuietWeek();
        $browser->request('GET', '/sign-in', server: self::PAGE);
        $browser->request('GET', '/open/form', server: self::PAGE + ['HTTP_HX_REQUEST' => 'true']);
        $browser->request('GET', '/open/form', server: ['HTTP_USER_AGENT' => self::COMPUTER, 'HTTP_ACCEPT' => 'application/json']);
        $browser->request('GET', '/c/spring-sale', server: self::PAGE);
        self::assertCount(1, $this->cameBack(), 'A project\'s excluded path, an htmx request, a request that is not for a page and a tracking link are none.');

        $this->aQuietWeek();
        $stranger = $this->browser();
        $stranger->request('GET', '/open/form', server: self::PAGE);
        self::assertCount(1, $this->cameBack(), 'A browser without the cookie costs nothing.');
        $browser->request('GET', '/open/form', server: self::PAGE);
        self::assertSame(['here', 'search.example.org'], $this->cameBack(), 'The browser came back from a page of this site it had left open.');
    }

    public function testComingBackIsNotNoticedWhenTheProjectSwitchesItOff(): void
    {
        $folder = Foundations::folder();
        $kernel = new TestKernel($folder, (string) getenv('CATTOPHP_TEST_DATABASE_URL'), marketing: true, marketingConfig: ['returns' => false]);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            self::assertFalse($container->has(VisitorReturns::class));
            self::assertInstanceOf(PersonJourneys::class, $container->get(PersonJourneys::class), 'The rest of the journey is there all the same.');
        } finally {
            $kernel->shutdown();
            Foundations::remove($folder);
            restore_exception_handler();
        }
    }

    public function testAPersonsJourneyMergesTheirVisitorsInTimeOrderWithBadgesStateAndFigures(): void
    {
        $journey = $this->service(Journey::class);
        $sales = $this->service(Sales::class);
        $journeys = $this->service(PersonJourneys::class);
        $tea = new Offer('product', 'tea-100', 'Rooibos tea, 100 bags');

        $phone = $this->arrive('spring-sale', 'fb1', self::PHONE);
        $journey->viewed($tea, $this->request($phone, self::PHONE));
        $carried = $journey->carry($this->request($phone, self::PHONE));
        self::assertNotNull($carried);
        $unknown = $journeys->of($this->visitor($phone));
        self::assertSame([PersonJourney::NOT_REGISTERED, 'Not registered', null, 1, 1, 'A phone'], [$unknown->state, $unknown->stateWords(), $unknown->account, $unknown->visits,
            $unknown->campaigns(), $unknown->devicesWords()]);

        $computer = $this->arrive('winter-warmers', 'em1', self::COMPUTER);
        $journey->registered('61', $carried, $this->request($computer));
        $journey->basketChanged($tea, true, 1, $this->request($computer));
        $report = new SaleReport('SO-1001', '61', null, new \DateTimeImmutable(), 'ZAR', 9500, lines: [new SaleLine('item:1', 'product', 'tea-100', 'Rooibos tea, 100 bags', 'Product', 1, 9500)]);
        self::assertNotNull($sales->placed($report, $this->request($computer)));
        self::assertSame(PersonJourney::WAITING, $journeys->of($this->visitor($phone))->state, 'An order waiting for payment.');
        $sales->failed('SO-1001', 'attempt-1', 'The card was declined by the bank.', new \DateTimeImmutable());
        self::assertNotNull($sales->paid($report, new \DateTimeImmutable(), 'Card'));
        self::assertNotNull($sales->refunded('SO-1001', 'refund:1', ['item:1' => 2000], new \DateTimeImmutable(), 'Money back'));

        $person = $journeys->of($this->visitor($computer));
        self::assertEquals($person->lines, $journeys->of($this->visitor($phone))->lines, 'Either visitor is the same person.');
        self::assertSame('61', $person->account);
        self::assertSame([PersonJourney::PAYING, 2, 2, 1, 1, 9500, 'A phone, then a computer', $person->countries],
            [$person->state, $person->visits, $person->campaigns(), $person->orders(), $person->paidOrders(), $person->paidMinor(), $person->devicesWords(), []], 'No country: the tests\' kernel has no network lookup.');
        self::assertSame([
            ['touch', 'Followed a link', 'Spring sale', ['First touch']],
            [Events::OFFER_VIEWED, 'Viewed Rooibos tea, 100 bags', 'Spring sale', []],
            ['touch', 'Followed a link', 'Winter warmers', ['Last touch']],
            [Events::REGISTERED, 'Registered', 'Winter warmers', ['Registration']],
            [Events::BASKET_ADDED, 'Put Rooibos tea, 100 bags in the basket', 'Winter warmers', []],
            [Events::ORDER_CREATED, 'Placed an order (SO-1001)', null, []],
            [Events::PAYMENT_FAILED, 'A payment did not go through (SO-1001)', null, ['Failed']],
            [Events::PAID, 'Paid R95 for SO-1001', 'Winter warmers', ['Sale']],
            [Events::REFUNDED, 'Refunded R20 on SO-1001', 'Winter warmers', []],
        ], array_map(static fn(JourneyLine $line): array => [$line->kind, $line->words, $line->kind === Events::ORDER_CREATED || $line->kind === Events::PAYMENT_FAILED ? null : $line->campaignName,
            $line->badgeWords()], $person->lines), 'Oldest first; the sale\'s money in place of its paid and refunded events.');
        $failed = array_values(array_filter($person->lines, static fn(JourneyLine $line): bool => $line->kind === Events::PAYMENT_FAILED))[0];
        self::assertSame('The card was declined by the bank.', $failed->detail);
        self::assertSame(['fb1 (Facebook)', 'Card'], [$person->lines[0]->detail, $person->lines[7]->detail]);
        self::assertSame(['SO-1001'], array_map(static fn($sale): string => $sale->reference, $person->sales));
        self::assertSame($person->visitors[0]->firstSeenAt, $person->firstSeenAt());
        $journey->signedIn('64', $this->request($computer));
        self::assertCount(count($person->lines), $journeys->of($this->visitor($computer))->lines, 'Another person signing in on the computer they share is not this person\'s.');

        $other = $this->arrive('spring-sale', 'fb1', self::COMPUTER);
        $journey->signedIn('62', $this->request($other));
        $registered = $journeys->ofAccount('62');
        self::assertSame([PersonJourney::REGISTERED, 1], [$registered?->state, $registered?->visits]);
        self::assertNull($journeys->ofAccount('63'), 'An account no visitor is tied to has no journey.');
    }

    /** Every visitor and click a week older, as though the visitor had been away. */
    private function aQuietWeek(): void
    {
        $this->connection->executeStatement("UPDATE cattophp_marketing_visitors SET last_seen_at = last_seen_at - interval '7 days', first_seen_at = first_seen_at - interval '7 days'");
        $this->connection->executeStatement("UPDATE cattophp_marketing_touches SET at = at - interval '7 days'");
        $this->connection->executeStatement("UPDATE cattophp_marketing_events SET at = at - interval '7 days'");
    }

    /** @return list<string> where each coming back came from, the newest first */
    private function cameBack(): array
    {
        return array_map(static fn(mixed $from): string => is_string($from) ? $from : '', $this->connection->fetchFirstColumn(
            "SELECT detail->>'from' FROM cattophp_marketing_events WHERE kind = 'came_back' ORDER BY id DESC"));
    }

    private function visitor(?string $token = null): Visitor
    {
        $visitors = $this->service(Visitors::class);
        if ($token !== null) {
            return $visitors->byToken($token) ?? throw new \LogicException('No such visitor.');
        }
        $id = $this->connection->fetchOne('SELECT id FROM cattophp_marketing_visitors ORDER BY id LIMIT 1');

        return $visitors->byId((int) $id) ?? throw new \LogicException('No visitor.');
    }

    /** A person arriving by a tracking link, through the service: their cookie's token. */
    private function arrive(string $campaign, string $publication, string $agent): string
    {
        $links = $this->service(TrackingLinks::class);
        $request = $this->request(null, $agent, '/c/' . $campaign . '/' . $publication);
        $opening = $links->open($campaign, $publication, $request) ?? throw new \LogicException('No such link.');

        return (string) $links->recordOpening($opening, $request)->cookie?->getValue();
    }

    private function request(?string $token, string $agent = self::COMPUTER, string $path = '/shop/tea-100'): Request
    {
        return Request::create($path, 'GET', [], $token === null ? [] : [Visitors::COOKIE => $token], [], ['HTTP_USER_AGENT' => $agent, 'REMOTE_ADDR' => '198.51.100.7']);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function service(string $class): object
    {
        $service = $this->container()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    private function browser(): KernelBrowser
    {
        $browser = new KernelBrowser($this->kernel);
        $browser->disableReboot();
        $browser->followRedirects(false);

        return $browser;
    }

    private function container(): ContainerInterface
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }
}
