<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Marketing\CampaignInput;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\Journey;
use CattoPHP\Marketing\PublicationInput;
use CattoPHP\Marketing\Publications;
use CattoPHP\Marketing\Sales\SaleLine;
use CattoPHP\Marketing\Sales\SaleReport;
use CattoPHP\Marketing\Sales\Sales;
use CattoPHP\Marketing\Sites\Site;
use CattoPHP\Marketing\Sites\SiteReports;
use CattoPHP\Marketing\Sites\Sites;
use CattoPHP\Marketing\Touches;
use CattoPHP\Schema\FoundationSteps;
use CattoPHP\Schema\SchemaRunner;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\MarketingSitesResponses;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Two sites on two databases, connected with one shared secret, each site's HTTP client handing its requests to the
 * other's kernel: https://example.com sends a campaign's email with a link to https://example.org, which sells. A click on
 * the first, the arrival on the second, a registration, an order paid and a refund there, and the reports delivered:
 * the first site then holds the sale as sold on the other site, counted for its campaign, and the registration in the
 * visitor's journey. Then a new secret is put in on one side (the old one still accepted meanwhile), changed again so the
 * two no longer share one (refused, kept waiting, both cards saying why), put in on the other (delivered by itself, the
 * old secrets dropped), and the clocks set seven minutes apart (refused, and the difference named).
 *
 * It needs a second database kept for these tests alone, CATTOPHP_TEST_DATABASE_URL_2 (README, "Developing it"), and is
 * skipped without one.
 */
final class MarketingSitesTwoSiteTest extends DatabaseTestCase
{
    private const PERSON = 'Mozilla/5.0 (X11; Linux x86_64; rv:146.0) Gecko/20100101 Firefox/146.0';
    private const SECRET = 'a-shared-secret-of-thirty-two-or-more-characters';
    private const NEW_SECRET = 'another-shared-secret-of-thirty-two-or-more-chars';
    private const THIRD_SECRET = 'a-third-shared-secret-of-thirty-two-or-more-chars';

    private Connection $second;
    private string $folder;
    /** The site that sends the campaign: https://example.com. */
    private TestKernel $sender;
    /** The site that sells: https://example.org. */
    private TestKernel $seller;

    protected function setUp(): void
    {
        $url = getenv('CATTOPHP_TEST_DATABASE_URL_2');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Two connected sites need a second database: set CATTOPHP_TEST_DATABASE_URL_2 to a PostgreSQL database kept for these tests (README, "Developing it").');
        }
        parent::setUp();
        $this->second = DriverManager::getConnection((new DsnParser())->parse($url));
        $this->second->executeStatement('DROP SCHEMA IF EXISTS public CASCADE');
        $this->second->executeStatement('CREATE SCHEMA public');
        (new SchemaRunner($this->second, array_reverse(FoundationSteps::all()), new MockClock('2026-11-09 10:00:00')))->migrate();
        $this->folder = Foundations::folder();
        $this->sender = new TestKernel($this->folder . '/sender', (string) getenv('CATTOPHP_TEST_DATABASE_URL'), ['APP_NAME' => 'Example Site', 'APP_URL' => 'https://example.com'],
            marketing: true, clock: 'now');
        $this->seller = new TestKernel($this->folder . '/seller', $url, ['APP_NAME' => 'Example Shop', 'APP_URL' => 'https://example.org'], marketing: true, clock: 'now');
        $this->sender->boot();
        $this->seller->boot();
        $this->answerFor($this->sender, $this->seller);
        $this->answerFor($this->seller, $this->sender);
    }

    protected function tearDown(): void
    {
        if (isset($this->sender)) {
            $this->sender->shutdown();
            $this->seller->shutdown();
            $this->second->close();
            Foundations::remove($this->folder);
            restore_exception_handler();
            restore_exception_handler();
            parent::tearDown();
        }
    }

    public function testASaleOnTheSellingSiteIsShownOnTheSendingSiteAndTroublesAreNamedOnBothCards(): void
    {
        // Each site's administrator connects the other with the same secret, and checks the connection.
        $toShop = $this->sites($this->sender)->add('Example Shop', 'https://example.org', self::SECRET);
        $toSender = $this->sites($this->seller)->add('Example Site', 'https://example.com', self::SECRET);
        self::assertNull($this->reports($this->sender)->check($toShop));
        self::assertNull($this->reports($this->seller)->check($toSender));
        self::assertTrue($this->sites($this->sender)->get('example-shop')?->isConnected());
        self::assertTrue($this->sites($this->seller)->get('example-site')?->isConnected());

        // The sending site's campaign emails a link to the shop; a person clicks it there and arrives at the shop.
        $this->campaigns($this->sender)->create(new CampaignInput('Law, November', 'law-nov26', destination: 'https://example.org/open/form'));
        $this->campaigns($this->sender)->activate('law-nov26');
        $this->publications($this->sender)->create('law-nov26', new PublicationInput('email'));
        $reader = $this->browser($this->sender);
        $reader->request('GET', '/c/law-nov26/em1');
        $forward = (string) $reader->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://example.org/open/form?cattophp_from=', $forward);
        $click = $this->touches($this->sender)->forCampaign($this->campaigns($this->sender)->require('law-nov26')->id)[0] ?? self::fail();
        $shopper = $this->browser($this->seller);
        $shopper->request('GET', $forward);
        self::assertSame('/open/form', $shopper->getResponse()->headers->get('Location'));
        $cookie = $shopper->getCookieJar()->get('cattophp_visitor')?->getValue() ?? self::fail('The shop remembers the visitor.');
        $arrival = $this->touches($this->seller)->byOrigin('example-site', (string) $click->reference) ?? self::fail();
        $shopCampaign = $this->campaigns($this->seller)->require('law-nov26');
        self::assertSame(['example-site', 'Law, November', 'em1', 'email'], [$shopCampaign->originSite, $shopCampaign->name, $arrival->originPublication, $arrival->source]);

        // On the shop: a registration, an order paid by card, and part of it refunded.
        $request = Request::create('https://example.org/account', cookies: ['cattophp_visitor' => $cookie], server: ['HTTP_USER_AGENT' => self::PERSON, 'REMOTE_ADDR' => '198.51.100.7']);
        $journey = $this->service($this->seller, Journey::class);
        $journey->registered('acct-7', null, $request);
        $shop = $this->sales($this->seller);
        $now = $this->clock($this->seller)->now();
        $order = new SaleReport('CL-00010482', 'acct-7', null, $now, 'ZAR', 150000, lines: [new SaleLine('course:fin-101', 'course', 'fin-101', 'Bookkeeping basics', 'Course', 1, 150000)]);
        self::assertNotNull($shop->placed($order, $request));
        self::assertNotNull($shop->paid($order, $now, 'Card'));
        self::assertNotNull($shop->refunded('CL-00010482', 'refund:1', ['course:fin-101' => 50000], $now, 'Money back'));
        $own = $shop->get('CL-00010482') ?? self::fail();
        self::assertSame([$arrival->id, 'link', null], [$own->lastTouchId, $own->lastBy, $own->originSite], 'The shop\'s own sale, counted for the arrival there.');
        $queued = $this->second->fetchFirstColumn('SELECT key FROM cattophp_marketing_reports_out ORDER BY id');
        self::assertSame(['order_placed:CL-00010482', 'sale_paid:CL-00010482', 'sale_refunded:CL-00010482:refund:1'], array_values(array_filter($queued, static fn (mixed $key): bool => !str_starts_with((string) $key, 'registered:'))));
        self::assertCount(4, $queued, 'And the registration.');
        $everything = (string) json_encode($this->second->fetchFirstColumn('SELECT body FROM cattophp_marketing_reports_out'));
        foreach (['acct-7', '198.51.100.7', $cookie, 'example.org'] as $personal) {
            self::assertStringNotContainsString($personal, $everything, 'No person, account, address or cookie goes to the other site.');
        }

        // The shop's task delivers them; the sending site now shows the sale and the registration as its campaign's.
        self::assertSame('4 reports delivered, 0 not delivered yet, 0 given up, 0 removed.', $this->reports($this->seller)->deliver($this->clock($this->seller)->now()));
        $sold = $this->sales($this->sender)->get('CL-00010482', 'example-shop') ?? self::fail('The sending site holds the sale.');
        $campaign = $this->campaigns($this->sender)->require('law-nov26');
        self::assertSame(['paid', 150000, 50000, 'example-shop', $click->id, $click->id, 'site', 'site', $campaign->id, $campaign->id, $click->publicationId],
            [$sold->state, $sold->paidMinor, $sold->refundedMinor, $sold->originSite, $sold->firstTouchId, $sold->lastTouchId, $sold->firstBy, $sold->lastBy, $sold->firstCampaignId,
                $sold->lastCampaignId, $sold->lastPublicationId]);
        self::assertSame([null, ''], [$sold->account, $sold->country], 'Nobody is named to it.');
        self::assertNull($this->sales($this->sender)->get('CL-00010482'), 'Never in the sending site\'s own takings.');
        $events = $this->service($this->sender, Events::class);
        $kinds = array_map(static fn ($event): string => $event->kind . ':' . ($event->originSite ?? 'here'), $events->forVisitor($click->visitorId ?? 0));
        self::assertContains(Events::REGISTERED . ':example-shop', $kinds);
        self::assertContains(Events::PAID . ':example-shop', $kinds);
        self::assertNotNull($this->sites($this->sender)->get('example-shop')?->lastReceivedAt);
        self::assertNotNull($this->sites($this->seller)->get('example-site')?->lastSentAt);
        self::assertSame('0 reports delivered, 0 not delivered yet, 0 given up, 0 removed.', $this->reports($this->seller)->deliver($this->clock($this->seller)->now()));

        // A new secret put in on the sending site only: the one both had is still accepted beside it, so nothing is lost meanwhile.
        $this->sites($this->sender)->changeSecret('example-shop', self::NEW_SECRET);
        self::assertNotNull($shop->chargedBack('CL-00010482', 'chargeback:1', 20000, $this->clock($this->seller)->now()));
        self::assertSame('1 report delivered, 0 not delivered yet, 0 given up, 0 removed.', $this->reports($this->seller)->deliver($this->clock($this->seller)->now()));
        self::assertSame(20000, $this->sales($this->sender)->get('CL-00010482', 'example-shop')?->chargedBackMinor);
        self::assertTrue($this->sites($this->sender)->get('example-shop')?->isChangingSecret(), 'The shop has not the new one yet, so the old one stays here.');

        // Changed again there, so the two sites no longer share a secret: the shop's next report is refused, kept, and both cards say why.
        $this->sites($this->sender)->changeSecret('example-shop', self::THIRD_SECRET);
        self::assertNotNull($shop->refunded('CL-00010482', 'refund:2', ['course:fin-101' => 10000], $this->clock($this->seller)->now(), 'Money back'));
        self::assertSame('0 reports delivered, 1 not delivered yet, 0 given up, 0 removed.', $this->reports($this->seller)->deliver($this->clock($this->seller)->now()));
        self::assertSame('The other site did not accept this site\'s signature. Check that both have the same shared secret.', $this->sites($this->seller)->get('example-site')?->lastProblem);
        self::assertFalse($this->site($this->seller, 'example-site')->isConnected());
        self::assertSame('message: its signature did not match.', $this->sites($this->sender)->get('example-shop')?->lastRefused);
        self::assertSame(1, $this->reports($this->seller)->counts($toSender)->waiting);
        self::assertSame(50000, $this->sales($this->sender)->get('CL-00010482', 'example-shop')?->refundedMinor);

        // The same secret put in on the shop: the report goes by itself at its next try, and each old secret is dropped.
        $this->sites($this->seller)->changeSecret('example-site', self::THIRD_SECRET);
        $this->later('+61 seconds');
        self::assertSame('1 report delivered, 0 not delivered yet, 0 given up, 0 removed.', $this->reports($this->seller)->deliver($this->clock($this->seller)->now()));
        self::assertSame(60000, $this->sales($this->sender)->get('CL-00010482', 'example-shop')?->refundedMinor);
        self::assertFalse($this->sites($this->sender)->get('example-shop')?->isChangingSecret(), 'The shop used the new one, so the old one is gone here.');
        self::assertNull($this->sites($this->seller)->get('example-site')?->lastProblem);
        self::assertNull($this->reports($this->sender)->check($this->sites($this->sender)->get('example-shop') ?? self::fail()));
        self::assertFalse($this->sites($this->seller)->get('example-site')?->isChangingSecret(), 'And the check, signed with the new one, drops it on the shop.');

        // The shop's clock seven minutes ahead: refused as too old, and the difference named on both cards.
        $this->clock($this->seller)->modify('+7 minutes');
        self::assertNotNull($shop->refunded('CL-00010482', 'refund:3', ['course:fin-101' => 5000], $this->clock($this->seller)->now(), 'Money back'));
        self::assertSame('0 reports delivered, 1 not delivered yet, 0 given up, 0 removed.', $this->reports($this->seller)->deliver($this->clock($this->seller)->now()));
        self::assertSame('The other site refused this site\'s signature because the two clocks are 7 minutes apart, more than the 5 minutes allowed. Set both clocks right.',
            $this->sites($this->seller)->get('example-site')?->lastProblem);
        // The answer's Date header holds whole seconds and each site's clock its own fraction of one, so the
        // difference read back is seven minutes to within a second.
        self::assertEqualsWithDelta(-420, $this->site($this->seller, 'example-site')->clockApartSeconds, 1);
        self::assertSame('message: its time was 7 minutes from this site\'s.', $this->sites($this->sender)->get('example-shop')?->lastRefused);
        self::assertSame(60000, $this->sales($this->sender)->get('CL-00010482', 'example-shop')?->refundedMinor, 'The third refund is not counted until it is delivered.');
    }

    /** A site's HTTP client hands each request to the other site's kernel, as the network would. */
    private function answerFor(TestKernel $from, TestKernel $to): void
    {
        $this->service($from, MarketingSitesResponses::class)->answer = static function (string $method, string $url, array $options) use ($to): ResponseInterface {
            $server = [];
            foreach (is_array($options['headers'] ?? null) ? $options['headers'] : [] as $line) {
                if (is_string($line) && str_contains($line, ': ')) {
                    [$name, $value] = explode(': ', $line, 2);
                    $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
                }
            }
            $server['CONTENT_TYPE'] = $server['HTTP_CONTENT_TYPE'] ?? 'application/json';
            $request = Request::create($url, $method, server: $server, content: is_string($options['body'] ?? null) ? $options['body'] : '');
            $answer = $to->handle($request);
            $headers = [];
            foreach ($answer->headers->allPreserveCase() as $name => $values) {
                foreach ($values as $value) {
                    $headers[] = $name . ': ' . $value;
                }
            }

            return new MockResponse((string) $answer->getContent(), ['http_code' => $answer->getStatusCode(), 'response_headers' => $headers]);
        };
    }

    /** Both sites' clocks moved on together. */
    private function later(string $by): void
    {
        $this->clock($this->sender)->modify($by);
        $this->clock($this->seller)->modify($by);
    }

    private function clock(TestKernel $kernel): MockClock
    {
        $clock = $kernel->getContainer()->get('clock');
        self::assertInstanceOf(MockClock::class, $clock);

        return $clock;
    }

    /** A site as a kernel keeps it now, read afresh. */
    private function site(TestKernel $kernel, string $key): Site
    {
        return $this->sites($kernel)->find($key) ?? self::fail('No site ' . $key . '.');
    }

    private function sites(TestKernel $kernel): Sites
    {
        return $this->service($kernel, Sites::class);
    }

    private function reports(TestKernel $kernel): SiteReports
    {
        return $this->service($kernel, SiteReports::class);
    }

    private function sales(TestKernel $kernel): Sales
    {
        return $this->service($kernel, Sales::class);
    }

    private function campaigns(TestKernel $kernel): Campaigns
    {
        return $this->service($kernel, Campaigns::class);
    }

    private function publications(TestKernel $kernel): Publications
    {
        return $this->service($kernel, Publications::class);
    }

    private function touches(TestKernel $kernel): Touches
    {
        return $this->service($kernel, Touches::class);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function service(TestKernel $kernel, string $class): object
    {
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $service = $container->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    private function browser(TestKernel $kernel): KernelBrowser
    {
        $browser = new KernelBrowser($kernel);
        $browser->disableReboot();
        $browser->followRedirects(false);
        $browser->setServerParameter('HTTP_USER_AGENT', self::PERSON);
        $browser->setServerParameter('REMOTE_ADDR', '198.51.100.7');

        return $browser;
    }
}
