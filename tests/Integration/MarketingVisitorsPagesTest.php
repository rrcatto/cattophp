<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Access\Roles;
use CattoPHP\Activity\ActivityLog;
use CattoPHP\Marketing\CampaignInput;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Journey;
use CattoPHP\Marketing\MarketingPermissions;
use CattoPHP\Marketing\Offer;
use CattoPHP\Marketing\PublicationInput;
use CattoPHP\Marketing\Publications;
use CattoPHP\Marketing\Sales\SaleFilter;
use CattoPHP\Marketing\Sales\SaleLine;
use CattoPHP\Marketing\Sales\SaleReport;
use CattoPHP\Marketing\Sales\Sales;
use CattoPHP\Marketing\TrackingLinks;
use CattoPHP\Marketing\Visitors;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\RecordingTransport;
use CattoPHP\Ui\Contract\AddressContract;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * The Visitors pages in a project that imports the pages' routes, the journey recorded through Marketing\Journey and
 * Marketing\Sales as a shop would: one row for each person with all their devices together and one for each visitor not
 * known yet, the filters, totals and download, one visitor's journey with which campaign gets their sale, Forget this
 * visitor's journey (confirmed, its permission, in the activity log, the totals unchanged), and who may see any of it.
 */
final class MarketingVisitorsPagesTest extends DatabaseTestCase
{
    private const COMPUTER = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Firefox/144.0';
    private const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';

    private string $folder;
    private TestKernel $kernel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = Foundations::folder();
        $this->kernel = new TestKernel($this->folder, (string) getenv('CATTOPHP_TEST_DATABASE_URL'), ['AUTH_TWO_STEP' => 'CHOICE', 'AUTH_SIGNIN' => 'EVERYONE'],
            stockPages: true, accounts: true, marketing: true);
        $this->kernel->boot();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        Foundations::remove($this->folder);
        restore_exception_handler();
        parent::tearDown();
    }

    public function testTheRoutesGiveEachPageItsAddressByReference(): void
    {
        $router = $this->kernel->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $routes = $router->getRouteCollection();
        foreach (['cattophp_marketing_visitors' => ['/marketing/visitors', 'GET'], 'cattophp_marketing_visitors_csv' => ['/marketing/visitors.csv', 'GET'],
            'cattophp_marketing_visitor' => ['/marketing/visitors/{reference}', 'GET'], 'cattophp_marketing_visitor_csv' => ['/marketing/visitors/{reference}/journey.csv', 'GET'],
            'cattophp_marketing_visitor_forget' => ['/marketing/visitors/{reference}/forget', 'POST']] as $name => [$path, $method]) {
            self::assertSame([$path, [$method]], [$routes->get($name)?->getPath(), $routes->get($name)?->getMethods()], $name);
        }
        self::assertSame([], AddressContract::rowNumbers($routes));
    }

    public function testVisitorsNeedThePeoplePermissionAndAreRefusedAsStockPagesAre(): void
    {
        $people = $this->shop();
        $stranger = $this->browser();
        $stranger->request('GET', '/marketing/visitors');
        self::assertSame(['/sign-in?return=/marketing/visitors', 303], [$stranger->getResponse()->headers->get('Location'), $stranger->getResponse()->getStatusCode()]);
        $anna = $this->signedIn('anna@example.com');
        $pages = ['/marketing/visitors', '/marketing/visitors.csv', '/marketing/visitors/' . $people['phone'], '/marketing/visitors/' . $people['phone'] . '/journey.csv'];
        foreach ($pages as $page) {
            $anna->request('GET', $page);
            self::assertSame(403, $anna->getResponse()->getStatusCode(), $page . ': no role.');
        }
        $this->role('WATCHER', [MarketingPermissions::VIEW], '2');
        foreach ($pages as $page) {
            $anna->request('GET', $page);
            self::assertSame(403, $anna->getResponse()->getStatusCode(), $page . ': seeing the campaigns is not seeing who the visitors are.');
        }
        $this->role('WATCHER', [MarketingPermissions::VIEW, MarketingPermissions::PEOPLE], '2');
        foreach ($pages as $page) {
            $anna->request('GET', $page);
            self::assertSame(200, $anna->getResponse()->getStatusCode(), $page);
        }
        $page = $anna->request('GET', '/marketing/visitors/' . $people['phone']);
        self::assertCount(0, $page->filter('#forget form'), 'Forgetting a journey needs MARKETING.CAMPAIGN.MANAGE too.');
        self::assertStringNotContainsString(' style=', $this->content($anna));
        $anna->request('POST', '/marketing/visitors/' . $people['phone'] . '/forget', ['_token' => 'any']);
        self::assertSame(403, $anna->getResponse()->getStatusCode());
        self::assertFalse($this->service(Visitors::class)->byReference($people['phone'])?->isAnonymous());
    }

    public function testThePersonIsOneRowWithAllTheirDevicesAndTheListFiltersTotalsAndDownloads(): void
    {
        $people = $this->shop();
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/marketing/visitors');
        self::assertSame('Visitors', trim($page->filter('h1')->text()));
        self::assertSame([['Visitor ' . $people['stranger'], 'Not registered', '1', '1', 'Not known', 'A computer'], ['Ayanda Khumalo', 'Paying customer', '2', '2', 'Not known', 'A phone and a computer']],
            $this->rows($page), 'The last seen first; Ayanda once, with her phone and her computer.');
        self::assertSame('/marketing/visitors/' . $people['phone'], $page->filter('#visitors-results tbody tr')->eq(1)->filter('a')->attr('href'), 'Her journey, by her first visitor.');
        self::assertSame(['2', '1', '1'], $this->totals($page), 'Visitors, known people and paying customers.');
        self::assertStringContainsString('Each person once, on 3 devices', $page->filter('#visitors-results')->text());
        self::assertSame(['everyone', '30d'], [$page->filter('input[name="state"][checked]')->attr('value'), $page->filter('input[name="period"][checked]')->attr('value')]);
        self::assertSame(['2 visitors', '1 visitor', 'None', 'None', '1 visitor'], $page->filter('#visitors-state .cl-ui-picture-choice-note')->each(static fn (Crawler $note): string => trim($note->text())));

        self::assertSame(['Ayanda Khumalo'], $this->names($owner->request('GET', '/marketing/visitors?state=paying')));
        self::assertSame(['Visitor ' . $people['stranger']], $this->names($owner->request('GET', '/marketing/visitors?state=not_registered')));
        self::assertSame(['Ayanda Khumalo'], $this->names($owner->request('GET', '/marketing/visitors?campaign=spring-sale')), 'Came by the spring sale.');
        self::assertSame(['Ayanda Khumalo'], $this->names($owner->request('GET', '/marketing/visitors?visitors_q=Khumalo')), 'By name, as the project finds its people.');
        self::assertSame(['Ayanda Khumalo'], $this->names($owner->request('GET', '/marketing/visitors?visitors_q=' . $people['computer'])), 'By any of her visitors\' references.');
        self::assertSame(['Visitor ' . $people['stranger']], $this->names($owner->request('GET', '/marketing/visitors?visitors_q=Visitor+' . $people['stranger'])));
        $page = $owner->request('GET', '/marketing/visitors?visitors_q=nobody-like-it');
        self::assertSame([[], ['0', '0', '0']], [$this->names($page), $this->totals($page)]);
        self::assertStringContainsString('Nobody matches', $page->filter('#visitors-results')->text());
        $page = $owner->request('GET', '/marketing/visitors?country=NA');
        self::assertStringContainsString('Nobody here', $page->filter('#visitors-results')->text());
        $page = $owner->request('GET', '/marketing/visitors?visitors_sort=visits&visitors_dir=desc');
        self::assertSame(['Ayanda Khumalo', 'Visitor ' . $people['stranger']], $this->names($page), 'The most visits first.');
        $page = $owner->request('GET', '/marketing/visitors?period=dates&from=2020-01-01&to=2020-01-31');
        self::assertSame([], $this->names($page), 'Nobody last seen then.');

        $page = $owner->request('GET', '/marketing/visitors?state=paying');
        $owner->request('GET', (string) $page->filter('a:contains("Download as CSV")')->attr('href'));
        $lines = $this->csv($owner);
        self::assertSame(['Visitor', 'Reference', 'State', 'Visits', 'Campaigns', 'Country', 'Devices', 'First seen', 'Last seen'], $lines[0]);
        self::assertSame(['Ayanda Khumalo', $people['phone'], 'Paying customer', '2', '2', 'Not known', 'A phone and a computer'], array_slice($lines[1], 0, 7), 'The rows shown.');
        self::assertSame([[], ['Visitors', '1'], ['Known people', '1'], ['Paying customers', '1']], [array_filter($lines[2]), $lines[3], $lines[4], $lines[5]], 'And their totals.');
        self::assertMatchesRegularExpression('/^attachment; filename=marketing-visitors-\d{4}-\d{2}-\d{2}\.csv$/', (string) $owner->getResponse()->headers->get('Content-Disposition'));
    }

    public function testOneVisitorsPageHoldsTheirJourneyWhichCampaignGetsTheirSaleAndWhoTheyAre(): void
    {
        $people = $this->shop();
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/marketing/visitors/' . $people['computer']);
        self::assertSame(200, $owner->getResponse()->getStatusCode());
        self::assertSame('Ayanda Khumalo', trim($page->filter('h1')->text()), 'Either of her visitors opens her journey.');
        $badges = $page->filter('.cl-page-head-badges .cl-ui-badge')->each(static fn (Crawler $badge): string => trim($badge->text()));
        self::assertSame(['Paying customer', 'A phone, then a computer'], [$badges[0], $badges[2]]);
        self::assertStringStartsWith('First seen ', $badges[1]);
        self::assertSame('/admin/people/ACC-0001', $page->filter('a:contains("Open this person\'s account")')->attr('href'));
        self::assertSame(['2', '2', '1', 'R321'], $page->filter('#figures .cl-ui-stat-value')->each(static fn (Crawler $value): string => trim($value->text())), 'Visits, campaigns, orders and paid.');
        self::assertSame('/marketing/sales/ORD-2001', $page->filter('#figures a.cl-ui-stat-link')->eq(2)->attr('href'), 'Her one order opens it.');
        self::assertStringContainsString('By card', $page->filter('#figures')->text());
        self::assertSame([
            ['Followed a link', 'Spring sale', 'First touch'],
            ['Viewed Rooibos tea, 100 bags', 'Spring sale', ''],
            ['Followed a link', 'Winter warmers', 'Last touch'],
            ['Registered', 'Winter warmers', 'Registration'],
            ['Placed an order (ORD-2001)', 'Winter warmers', ''],
            ['Paid R321 for ORD-2001', 'Winter warmers', 'Sale'],
        ], $page->filter('#journey tbody tr')->each(static fn (Crawler $row): array => [trim($row->filter('td')->eq(1)->text()), trim($row->filter('td')->eq(2)->text()),
            trim($row->filter('td')->eq(4)->text())]), 'Oldest first, from both her devices.');
        self::assertSame('/marketing/sales/ORD-2001', $page->filter('#journey tbody tr')->eq(5)->filter('a')->first()->attr('href'));
        $which = $page->filter('#which-campaign')->text();
        self::assertStringContainsString('Last touch: Winter warmers, through Email, November letter.', $which);
        self::assertStringContainsString('First touch: Spring sale, through Facebook, Tea lovers group.', $which);
        self::assertStringContainsString('Both were within 30 days of the payment.', $which);
        self::assertStringContainsString('The R321 is counted once, for whichever of the two the reports are set to: the last touch now, Winter warmers.', $which);
        self::assertSame('/marketing/settings#settings-marketing', $page->filter('#which-campaign a:contains("Change how a sale is counted")')->attr('href'));
        self::assertSame(['Ayanda Khumalo', 'ACC-0001', 'Not known', 'A phone and a computer'], [$this->fact($page, 'Name'), $this->fact($page, 'Account reference'), $this->fact($page, 'Country'),
            $this->fact($page, 'Devices')]);
        self::assertStringContainsString('IP addresses are kept for 30 days and then removed. The country stays.', $page->filter('#person')->text());
        self::assertDoesNotMatchRegularExpression('/\b198\.51\.100\.\d+\b/', $this->content($owner), 'No IP address is ever shown.');
        self::assertSame(['Visitor ' . $people['stranger']], $page->filter('#others tbody td[data-label="Visitor"]')->each(static fn (Crawler $cell): string => trim($cell->text())), 'Others, not her.');
        self::assertSame('/marketing/visitors', $page->filter('#others a:contains("View all")')->attr('href'));
        self::assertSame('Forget Ayanda Khumalo\'s journey? Their visitors are made anonymous and their sales no longer name them. The campaigns\' clicks and money stay. This cannot be undone.',
            $page->filter('#forget button')->attr('data-confirm'), 'Asked first.');

        $owner->request('GET', '/marketing/visitors/' . $people['phone'] . '/journey.csv');
        $lines = $this->csv($owner);
        self::assertSame(['When', 'What happened', 'Campaign', 'Detail', 'Badges'], $lines[0]);
        self::assertSame(['Followed a link', 'Spring sale', 'fb1 (Facebook)', 'First touch'], array_slice($lines[1], 1));
        self::assertCount(7, $lines, 'Each line of the journey.');

        $page = $owner->request('GET', '/marketing/visitors/' . $people['stranger']);
        self::assertSame(['Visitor ' . $people['stranger'], 'Not registered'], [trim($page->filter('h1')->text()), trim($page->filter('.cl-page-head-badges .cl-ui-badge')->first()->text())]);
        self::assertCount(0, $page->filter('#which-campaign'), 'No paid sale.');
        self::assertCount(0, $page->filter('a:contains("Open this person\'s account")'));
        $owner->request('GET', '/marketing/visitors/zzzzzzzz');
        self::assertSame(404, $owner->getResponse()->getStatusCode(), 'Nobody has that reference.');
    }

    public function testForgettingAJourneyNamesNobodyKeepsTheTotalsAndIsLogged(): void
    {
        $people = $this->shop();
        $sales = $this->service(Sales::class);
        $before = $sales->totals(new SaleFilter(state: 'all'));
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/marketing/visitors/' . $people['phone']);
        $owner->request('POST', '/marketing/visitors/' . $people['phone'] . '/forget', ['_token' => 'not-the-token']);
        self::assertStringContainsString('That form had run out', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        self::assertFalse($this->service(Visitors::class)->byReference($people['phone'])?->isAnonymous(), 'Nothing forgotten.');
        $owner->submit($page->filter('#forget form')->form());
        self::assertSame('/marketing/visitors', $owner->getResponse()->headers->get('Location'));
        $page = $owner->followRedirect();
        self::assertStringContainsString('Ayanda Khumalo\'s journey is forgotten: 2 visitors are made anonymous', $page->filter('.cl-flash-stack')->text());
        self::assertSame(['Visitor ' . $people['stranger']], $this->names($page), 'Only the stranger is left.');
        foreach (['phone', 'computer'] as $device) {
            $owner->request('GET', '/marketing/visitors/' . $people[$device]);
            self::assertSame(404, $owner->getResponse()->getStatusCode(), $device . ': simply not found now.');
        }
        $after = $sales->totals(new SaleFilter(state: 'all'));
        self::assertSame([$before->sales, $before->grossMinor, $before->netMinor()], [$after->sales, $after->grossMinor, $after->netMinor()], 'The money stays.');
        self::assertNull($sales->get('ORD-2001')?->account, 'Her sale names nobody.');
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM cattophp_marketing_events WHERE account = '1'"), 'Nor do her events.');
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_marketing_touches'), 'Her clicks stay in the totals.');
        $entry = $this->service(ActivityLog::class)->recent(1, 'marketing.visitor.forgotten')[0] ?? null;
        self::assertSame(['marketing_visitor', $people['phone'], '2'], [$entry?->subjectType, $entry?->subjectId, (string) ($entry->details['visitors'] ?? '')]);
        $page = $owner->request('GET', '/admin/activity?action=marketing.visitor.forgotten');
        self::assertStringContainsString('Visitor\'s journey forgotten', $page->filter('tbody')->text());
        self::assertCount(0, $page->filter('a[href="/marketing/visitors/' . $people['phone'] . '"]'), 'An anonymous visitor is linked to nothing.');
    }

    public function testWithNoVisitorTheListSaysWhatToDoNext(): void
    {
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/marketing/visitors');
        self::assertStringContainsString('No visitors yet', $page->filter('#visitors-results')->text());
        self::assertSame(['0', '0', '0'], $this->totals($page));
    }

    /**
     * Ayanda: her phone came by the spring sale's Facebook post two days ago and looked at the tea; she registered on her
     * computer, which came by the winter email, and paid ORD-2001 there by card. A stranger's computer came by the winter
     * email and did nothing more. Gives each visitor's reference.
     *
     * @return array{phone:string,computer:string,stranger:string}
     */
    private function shop(): array
    {
        $campaigns = $this->service(Campaigns::class);
        $publications = $this->service(Publications::class);
        $campaigns->create(new CampaignInput('Spring sale', 'spring-sale', destination: '/shop'));
        $campaigns->activate('spring-sale');
        $publications->create('spring-sale', new PublicationInput('facebook', cost: '250', place: 'Tea lovers group'));
        $campaigns->create(new CampaignInput('Winter warmers', 'winter-warmers', destination: '/shop'));
        $campaigns->activate('winter-warmers');
        $publications->create('winter-warmers', new PublicationInput('email', place: 'November letter'));
        $journey = $this->service(Journey::class);
        $phone = $this->click('spring-sale', 'fb1', self::PHONE);
        $journey->viewed(new Offer('product', 'tea-100', 'Rooibos tea, 100 bags'), $this->request($phone, self::PHONE));
        $carried = $journey->carry($this->request($phone, self::PHONE));
        foreach (['cattophp_marketing_touches', 'cattophp_marketing_events'] as $table) {
            $this->connection->executeStatement('UPDATE ' . $table . " SET at = at - interval '2 days'");
        }
        $this->connection->executeStatement("UPDATE cattophp_marketing_visitors SET first_seen_at = first_seen_at - interval '2 days', last_seen_at = last_seen_at - interval '2 days'");
        $computer = $this->click('winter-warmers', 'em1', self::COMPUTER);
        $journey->registered('1', $carried, $this->request($computer, self::COMPUTER));
        $sales = $this->service(Sales::class);
        $report = new SaleReport('ORD-2001', '1', null, new DateTimeImmutable(), 'ZAR', 32100, lines: [new SaleLine('item:1', 'product', 'tea-100', 'Rooibos tea, 100 bags', 'Product', 2, 19000, 24000, 1900),
            new SaleLine('item:2', 'product', 'mug-01', 'Enamel mug', 'Product', 1, 15000)]);
        self::assertNotNull($sales->placed($report, $this->request($computer, self::COMPUTER)));
        self::assertNotNull($sales->paid($report, new DateTimeImmutable(), 'Card'));
        $stranger = $this->click('winter-warmers', 'em1', self::COMPUTER);
        $visitors = $this->service(Visitors::class);
        $reference = static fn (?string $token): string => $token === null ? '' : ($visitors->byToken($token)->reference ?? '');

        return ['phone' => $reference($phone), 'computer' => $reference($computer), 'stranger' => $reference($stranger)];
    }

    /** A browser opening a campaign's tracking link for the first time: its visitor's token. */
    private function click(string $campaign, string $publication, string $agent): string
    {
        $links = $this->service(TrackingLinks::class);
        $request = $this->request(null, $agent, '/c/' . $campaign . '/' . $publication);
        $opening = $links->open($campaign, $publication, $request) ?? throw new \LogicException('No such link.');

        return (string) $links->recordOpening($opening, $request)->cookie?->getValue();
    }

    private function request(?string $token, string $agent, string $path = '/shop/tea-100'): Request
    {
        return Request::create($path, 'GET', [], $token === null ? [] : [Visitors::COOKIE => $token], [], ['HTTP_USER_AGENT' => $agent, 'REMOTE_ADDR' => '198.51.100.7']);
    }

    /** @return list<list<string>> each row's visitor, state, visits, campaigns, country and devices */
    private function rows(Crawler $page): array
    {
        return $page->filter('#visitors-results tbody tr')->each(static fn (Crawler $row): array => array_map(static fn (string $label): string => trim($row->filter('td[data-label="' . $label . '"]')->text()),
            ['Visitor', 'State', 'Visits', 'Campaigns', 'Country', 'Devices']));
    }

    /** @return list<string> */
    private function names(Crawler $page): array
    {
        return $page->filter('#visitors-results tbody td[data-label="Visitor"]')->each(static fn (Crawler $cell): string => trim($cell->text()));
    }

    /** @return list<string> */
    private function totals(Crawler $page): array
    {
        return $page->filter('#visitors-results .cl-ui-stat-value')->each(static fn (Crawler $value): string => trim($value->text()));
    }

    private function fact(Crawler $page, string $label): string
    {
        foreach ($page->filter('#person dt') as $index => $term) {
            if (trim($term->textContent) === $label) {
                return trim($page->filter('#person dd')->eq($index)->text());
            }
        }

        return 'none: ' . $label;
    }

    /** @return list<list<string>> */
    private function csv(KernelBrowser $browser): array
    {
        $lines = [];
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, (string) preg_replace('/^\x{FEFF}/u', '', $this->content($browser)));
        rewind($stream);
        while (($line = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $lines[] = array_map(static fn (?string $cell): string => (string) $cell, $line);
        }
        fclose($stream);

        return $lines;
    }

    /** @param list<string> $permissions */
    private function role(string $key, array $permissions, string $account): void
    {
        $roles = $this->service(Roles::class);
        $roles->save($key, 'Marketing helper', '', $permissions);
        $roles->grant($account, $key);
    }

    private function content(KernelBrowser $browser): string
    {
        return $browser->getInternalResponse()->getContent();
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function service(string $class): object
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $service = $container->get($class);
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

    /** A browser signed in as someone, by the emailed link, with no two-step set up. */
    private function signedIn(string $email): KernelBrowser
    {
        $browser = $this->browser();
        $browser->setServerParameter('HTTP_USER_AGENT', self::COMPUTER);
        $browser->setServerParameter('REMOTE_ADDR', '203.0.113.9');
        $page = $browser->request('GET', '/sign-in');
        $browser->submit($page->filter('form')->form(['email' => $email]));
        $transport = $this->service(RecordingTransport::class);
        self::assertSame(1, preg_match('#https://example\.com(/sign-in/link/[0-9a-f]{64})#', (string) $transport->last()->getTextBody(), $found));
        $page = $browser->request('GET', $found[1] ?? '');
        $browser->submit($page->filter('form')->form());
        self::assertSame('/', $browser->getResponse()->headers->get('Location'), 'Signed in.');

        return $browser;
    }
}
