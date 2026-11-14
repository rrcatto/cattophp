<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Access\Roles;
use CattoPHP\Marketing\CampaignInput;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\MarketingPermissions;
use CattoPHP\Marketing\PublicationInput;
use CattoPHP\Marketing\Publications;
use CattoPHP\Marketing\Sales\ReportedSale;
use CattoPHP\Marketing\Sales\SaleFilter;
use CattoPHP\Marketing\Sales\SaleLine;
use CattoPHP\Marketing\Sales\SaleReport;
use CattoPHP\Marketing\Sales\Sales;
use CattoPHP\Marketing\Sites\Sites;
use CattoPHP\Marketing\Touches;
use CattoPHP\Marketing\TrackingLinks;
use CattoPHP\Marketing\Visitors;
use CattoPHP\Settings\Settings;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\RecordingTransport;
use CattoPHP\Ui\Contract\AddressContract;
use CattoPHP\Ui\Contract\TemplateContract;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * The Sales pages, a campaign's sales and money and the overview's Latest sales, in a project that imports the pages'
 * routes, the sales reported through Marketing\Sales as a shop would: every sale listed with or without a campaign, its
 * filters and totals (Sales::totals() of the same filter, a sale in another currency left out and named), each sale
 * counted once for its first or its last touch, both downloads reading back the rows and totals shown, one sale's page,
 * a connected site's sale, names only for a reader who may see who customers are, and the templates' rules.
 */
final class MarketingSalesPagesTest extends DatabaseTestCase
{
    private const COMPUTER = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Firefox/144.0';
    private const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';
    private const SECRET = 'a-shared-secret-of-thirty-two-or-more-characters';

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

    public function testTheRoutesGiveEachPageItsAddressAndNoRowNumber(): void
    {
        $router = $this->kernel->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $routes = $router->getRouteCollection();
        foreach (['cattophp_marketing_sales' => '/marketing/sales', 'cattophp_marketing_sales_csv' => '/marketing/sales.csv', 'cattophp_marketing_sales_lines_csv' => '/marketing/sales-lines.csv',
            'cattophp_marketing_sale' => '/marketing/sales/{reference}', 'cattophp_marketing_sale_from_site' => '/marketing/sales/{site}/{reference}'] as $name => $path) {
            self::assertSame([$path, ['GET']], [$routes->get($name)?->getPath(), $routes->get($name)?->getMethods()], $name);
        }
        self::assertSame([], AddressContract::rowNumbers($routes));
        $router->getContext()->setMethod('GET');
        self::assertSame('cattophp_marketing_sales_csv', $router->match('/marketing/sales.csv')['_route'], 'The download is no sale\'s reference.');
        self::assertSame('cattophp_marketing_sale', $router->match('/marketing/sales/CL-00010482')['_route']);
        self::assertSame(['cattophp_marketing_sale_from_site', 'example-org'], [$router->match('/marketing/sales/example-org/EX-1001')['_route'], $router->match('/marketing/sales/example-org/EX-1001')['site']]);
    }

    public function testEachPageIsRefusedAsStockPagesAreAndCustomersAreNamedOnlyWithThePeoplePermission(): void
    {
        $this->shop();
        $stranger = $this->browser();
        $stranger->request('GET', '/marketing/sales');
        self::assertSame(['/sign-in?return=/marketing/sales', 303], [$stranger->getResponse()->headers->get('Location'), $stranger->getResponse()->getStatusCode()]);
        $anna = $this->signedIn('anna@example.com');
        foreach (['/marketing/sales', '/marketing/sales.csv', '/marketing/sales-lines.csv', '/marketing/sales/ORD-1001'] as $page) {
            $anna->request('GET', $page);
            self::assertSame(403, $anna->getResponse()->getStatusCode(), $page . ': Anna holds no role that allows it.');
        }

        $this->role('WATCHER', [MarketingPermissions::VIEW], '2');
        $page = $anna->request('GET', '/marketing/sales');
        self::assertSame(200, $anna->getResponse()->getStatusCode());
        self::assertSame(['A customer'], array_values(array_unique($page->filter('#sales-results tbody td[data-label="Customer"]')->each(static fn (Crawler $cell): string => trim($cell->text())))));
        self::assertCount(0, $page->filter('#sales-results tbody td[data-label="Customer"] a'), 'No journey to open.');
        self::assertStringNotContainsString('Ayanda', $this->content($anna));
        $page = $anna->request('GET', '/marketing/sales/ORD-1001');
        self::assertStringContainsString('A customer', $page->filter('#facts')->text());
        self::assertCount(0, $page->filter('a:contains("Open the customer\'s journey")'));
        self::assertSame('/admin/orders/ORD-1001', $page->filter('a:contains("Open the order")')->attr('href'));
        $anna->request('GET', '/marketing/sales.csv');
        self::assertStringNotContainsString('Ayanda', $this->content($anna), 'Nor in the file.');
        $page = $anna->request('GET', '/marketing/campaigns/winter-warmers');
        self::assertStringNotContainsString('Ayanda', $page->filter('#orders')->text());
        $anna->request('GET', '/marketing/visitors');
        self::assertSame(403, $anna->getResponse()->getStatusCode(), 'Visitors need the people permission.');

        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/marketing/sales');
        self::assertContains('Ayanda Khumalo', $page->filter('#sales-results tbody td[data-label="Customer"]')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertNotSame('', (string) $page->filter('#sales-results tbody td[data-label="Customer"] a')->first()->attr('href'), 'A name opens the journey.');
    }

    public function testEverySaleIsListedWithTotalsOfTheSameFilterAndAnotherCurrencyLeftOut(): void
    {
        $this->shop();
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/marketing/sales');
        self::assertSame('Sales', trim($page->filter('h1')->text()));
        self::assertSame(['ORD-1003', 'ORD-1001', 'ORD-0999'], $this->orders($page), 'Paid to start, the newest first, with or without a campaign.');
        self::assertSame(['paid', '30d', 'last'], [$page->filter('input[name="state"][checked]')->attr('value'), $page->filter('input[name="period"][checked]')->attr('value'),
            $page->filter('input[name="counts"][checked]')->attr('value')]);
        self::assertSame(['2', '2', 'R471', 'R19', 'R0', 'R50', 'R40', 'R381', 'R235.50'], $this->totals($page),
            'Sales, paying customers, gross, discounts, wallet credit, refunds, chargebacks, net revenue and the average order, in rand only.');
        self::assertStringContainsString('1 sale in USD is listed with its own currency and left out of these totals.', $page->filter('#sales-totals')->text());
        self::assertStringContainsString('Refunds and chargebacks are those of the sales listed, whenever they were made.', $page->filter('#sales-totals')->text());
        $row = $this->row($page, 'ORD-1001');
        self::assertSame(['Ayanda Khumalo', 'Rooibos tea, 100 bags and 1 more', 'Winter warmers', 'Email, November letter', 'R321', 'R50', 'R271', 'Partly refunded'],
            [$this->cell($row, 'Customer'), $this->cell($row, 'Bought'), $this->cell($row, 'Campaign'), $this->cell($row, 'Came through'), $this->cell($row, 'Gross'), $this->cell($row, 'Refunds'),
                $this->cell($row, 'Net'), $this->cell($row, 'State')]);
        self::assertSame('/marketing/sales/ORD-1001', $row->filter('td[data-label="Order"] a')->attr('href'));
        self::assertSame(['No campaign', 'R40 R40 charged back', 'Charged back'], [$this->cell($this->row($page, 'ORD-1003'), 'Came through'), $this->cell($this->row($page, 'ORD-1003'), 'Refunds'),
            $this->cell($this->row($page, 'ORD-1003'), 'State')]);
        self::assertSame(['USD 150.00', 'Paid Not in the totals'], [$this->cell($this->row($page, 'ORD-0999'), 'Gross'), $this->cell($this->row($page, 'ORD-0999'), 'State')]);
        self::assertSame(['Gross', 'Refunds', 'Net'], $page->filter('#sales-results thead th.text-end')->each(static fn (Crawler $th): string => trim(str_replace(['↕', '▲', '▼'], '', $th->text()))),
            'Numbers to the right.');
        self::assertSame(['3 sales', '1 sale', '1 sale', '5 sales'], $page->filter('#sales-state .cl-ui-picture-choice-note')->each(static fn (Crawler $note): string => trim($note->text())),
            'Each state\'s sales, under the other choices.');

        $page = $owner->request('GET', '/marketing/sales?state=all');
        self::assertSame(['ORD-1004', 'ORD-1003', 'ORD-1002', 'ORD-1001', 'ORD-0999'], $this->orders($page), 'Every state, each by when it was paid, else placed.');
        self::assertSame(['4', '2', 'R471'], array_slice($this->totals($page), 0, 3), 'Waiting and cancelled are counted, never in the revenue.');
        self::assertContains('R320', $this->totals($page), 'What the waiting sale is to pay, apart.');
        self::assertSame(['Not paid R320 to pay', 'Waiting for payment'], [$this->cell($this->row($page, 'ORD-1002'), 'Gross'), $this->cell($this->row($page, 'ORD-1002'), 'State')]);
        $page = $owner->request('GET', '/marketing/sales?state=cancelled');
        self::assertSame([['ORD-1004'], ['1', '0', 'R0']], [$this->orders($page), array_slice($this->totals($page), 0, 3)]);
    }

    public function testEachFilterNarrowsTheSalesAndTheirTotalsAndASaleCountsForOneTouchAtATime(): void
    {
        $this->shop();
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/marketing/sales?campaign=any');
        self::assertSame([['ORD-1001'], 'R271'], [$this->orders($page), $this->totals($page)[7]], 'Any campaign.');
        $page = $owner->request('GET', '/marketing/sales?campaign=none');
        self::assertSame([['ORD-1003', 'ORD-0999'], 'R110'], [$this->orders($page), $this->totals($page)[7]], 'No campaign: the rand sale only in the totals.');
        $page = $owner->request('GET', '/marketing/sales?campaign=spring-sale');
        self::assertSame([], $this->orders($page), 'Counted for the last touch, the spring sale has no paid sale.');
        self::assertStringContainsString('No sales here', $page->filter('#sales-results')->text());
        $page = $owner->request('GET', '/marketing/sales?campaign=spring-sale&counts=first');
        self::assertSame([['ORD-1001'], 'first', 'Spring sale', 'Facebook, Tea lovers group'], [$this->orders($page), $page->filter('input[name="counts"][checked]')->attr('value'),
            $this->cell($this->row($page, 'ORD-1001'), 'Campaign'), $this->cell($this->row($page, 'ORD-1001'), 'Came through')], 'The first touch, chosen here.');
        self::assertCount(2, $page->filter('#sales-publication option'), 'Its publications to choose from.');
        $page = $owner->request('GET', '/marketing/sales?campaign=spring-sale&counts=first&publication=fb1');
        self::assertSame(['ORD-1001'], $this->orders($page));
        $page = $owner->request('GET', '/marketing/sales?channel=email');
        self::assertSame(['ORD-1001'], $this->orders($page), 'The channel of the touch it counts for.');
        $page = $owner->request('GET', '/marketing/sales?state=all&offer=bundle:gift-box');
        self::assertSame(['ORD-1002'], $this->orders($page), 'Sales holding the bundle.');
        $page = $owner->request('GET', '/marketing/sales?state=all&sales_q=1003');
        self::assertSame([['ORD-1003'], '1'], [$this->orders($page), $this->totals($page)[0]], 'Searched, with its totals.');
        $page = $owner->request('GET', '/marketing/sales?country=NA');
        self::assertSame([], $this->orders($page), 'No sale from a browser in Namibia.');
        $page = $owner->request('GET', '/marketing/sales?sales_sort=net&sales_dir=desc');
        self::assertSame(['ORD-1001', 'ORD-0999', 'ORD-1003'], $this->orders($page), 'Sorted by net.');
        self::assertSame('descending', $page->filter('th[aria-sort]')->attr('aria-sort'));
        $page = $owner->request('GET', '/marketing/sales?period=dates&from=2020-01-01&to=2020-01-31');
        self::assertSame([[], ['0', '0', 'R0']], [$this->orders($page), array_slice($this->totals($page), 0, 3)]);

        $sales = $this->service(Sales::class);
        $before = $sales->totals(new SaleFilter());
        $last = $sales->totals(new SaleFilter(campaign: SaleFilter::ANY_CAMPAIGN, model: 'last'))->netMinor() + $sales->totals(new SaleFilter(campaign: SaleFilter::NO_CAMPAIGN, model: 'last'))->netMinor();
        $first = $sales->totals(new SaleFilter(campaign: SaleFilter::ANY_CAMPAIGN, model: 'first'))->netMinor() + $sales->totals(new SaleFilter(campaign: SaleFilter::NO_CAMPAIGN, model: 'first'))->netMinor();
        self::assertSame([$before->netMinor(), $before->netMinor()], [$last, $first], 'With and without a campaign come to every sale, once, under either touch.');
    }

    public function testBothDownloadsHoldTheRowsAndTotalsShown(): void
    {
        $this->shop();
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/marketing/sales?campaign=none');
        self::assertSame('/marketing/sales.csv?campaign=none&period=30d&sales_dir=desc&sales_sort=when', self::sortedQuery((string) $page->filter('a:contains("Download as CSV")')->attr('href')));
        $owner->request('GET', '/marketing/sales.csv?campaign=none');
        $lines = $this->csv($owner);
        self::assertSame(['Paid or placed', 'Which', 'Order', 'Customer', 'Bought', 'Campaign', 'Came through', 'Country', 'Gross', 'Refunds', 'Net', 'State', 'Sold on', 'Currency', 'To pay'], $lines[0]);
        self::assertSame(['ORD-1003', 'ORD-0999'], [$lines[1][2], $lines[2][2]], 'The rows shown, in their order.');
        self::assertSame(['Paid', 'ORD-1003', 'Not known', 'Enamel mug', 'No campaign', 'No campaign', 'Not known', '150.00', '40.00', '110.00', 'Charged back', '', 'ZAR', ''],
            array_slice($lines[1], 1));
        self::assertSame(['USD', '150.00'], [$lines[2][13], $lines[2][8]]);
        $totals = [];
        foreach (array_slice($lines, 4) as $line) {
            $totals[$line[0]] = $line[1] ?? '';
        }
        self::assertSame(['1', '1', '150.00', '40.00', '110.00', 'ZAR'], [$totals['Sales'], $totals['Paying customers'], $totals['Gross revenue'], $totals['Chargebacks'], $totals['Net revenue'],
            $totals['Currency']], 'The page\'s totals, as numbers.');
        self::assertArrayHasKey('1 sale in USD is listed with its own currency and left out of these totals.', $totals);
        self::assertMatchesRegularExpression('/^attachment; filename=marketing-sales-\d{4}-\d{2}-\d{2}\.csv$/', (string) $owner->getResponse()->headers->get('Content-Disposition'));

        $owner->request('GET', '/marketing/sales-lines.csv?state=all&campaign=any&counts=first');
        $lines = $this->csv($owner);
        self::assertSame(['Order', 'Paid or placed', 'State', 'Offer', 'Kind', 'Offer key', 'Quantity', 'Price', 'Discount', 'Paid', 'Refunded', 'Charged back', 'Net', 'Campaign', 'Currency', 'Holds'], $lines[0]);
        $rows = array_map(static fn (array $line): array => [$line[0], $line[3], $line[6], $line[9], $line[10], $line[13], $line[15]], array_slice($lines, 1));
        sort($rows);
        self::assertSame([['ORD-1001', 'Enamel mug', '1', '150.00', '50.00', 'Spring sale', ''], ['ORD-1001', 'Rooibos tea, 100 bags', '2', '171.00', '0.00', 'Spring sale', ''],
            ['ORD-1002', 'Gift box', '1', '320.00', '0.00', 'Spring sale', 'product:tea-100, product:mug-01']], $rows, 'One row for each line of the sales listed.');
    }

    public function testOneSalesPageHoldsItsLinesMoneyAndWhichCampaignGetsIt(): void
    {
        $this->shop();
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/marketing/sales/ORD-1001');
        self::assertSame(200, $owner->getResponse()->getStatusCode());
        self::assertSame('Order ORD-1001', trim($page->filter('h1')->text()));
        self::assertSame(['Partly refunded', 'Winter warmers', 'Email'], $page->filter('.cl-page-head-badges .cl-ui-badge')->each(static fn (Crawler $badge): string => trim($badge->text())));
        self::assertSame('/admin/orders/ORD-1001', $page->filter('a:contains("Open the order")')->attr('href'));
        self::assertMatchesRegularExpression('#^/marketing/visitors/[a-z0-9]{8}$#', (string) $page->filter('a:contains("Open the customer\'s journey")')->attr('href'));
        self::assertStringContainsString('Ayanda Khumalo', $page->filter('#facts')->text());
        self::assertSame([['Rooibos tea, 100 bags', '2', 'Was R240 R190', 'R19', 'R171', 'R0'], ['Enamel mug', '1', 'R150', 'R0', 'R150', 'R50']],
            $page->filter('#lines tbody tr')->each(fn (Crawler $row): array => [$this->cell($row, 'Offer'), $this->cell($row, 'Quantity'), $this->cell($row, 'Price'), $this->cell($row, 'Discount'),
                $this->cell($row, 'Paid'), $this->cell($row, 'Refunded')]));
        self::assertSame([['Paid', 'R321', 'Card'], ['Refund', '-R50', 'Money back']],
            $page->filter('#movements tbody tr')->each(fn (Crawler $row): array => [$this->cell($row, 'What'), $this->cell($row, 'Amount'), $this->cell($row, 'How')]));
        self::assertSame([['Last touch Counted now', 'Winter warmers', 'Email, November letter', 'A tracking link'], ['First touch', 'Spring sale', 'Facebook, Tea lovers group', 'A tracking link']],
            $page->filter('#which-campaign tbody tr')->each(fn (Crawler $row): array => [$this->cell($row, 'Touch'), $this->cell($row, 'Campaign'), $this->cell($row, 'Came through'),
                $this->cell($row, 'How it was found')]));
        self::assertStringContainsString('When it was paid, a sale counted for a campaign within 30 days of the click.', $page->filter('#which-campaign')->text());
        self::assertSame(['R321', 'R19', 'R50', 'R0', 'R271'], $page->filter('#money dd')->each(static fn (Crawler $dd): string => trim($dd->text())));
        self::assertCount(0, $page->filter('#connected-site'), 'Nothing was reported of it to a connected site.');

        $page = $owner->request('GET', '/marketing/sales/ORD-1002');
        self::assertSame(['Waiting for payment', 'Spring sale', 'Facebook'], $page->filter('.cl-page-head-badges .cl-ui-badge')->each(static fn (Crawler $badge): string => trim($badge->text())));
        self::assertStringContainsString('Holds Rooibos tea, 100 bags, Enamel mug', $page->filter('#lines')->text(), 'A bundle\'s offers in small text.');
        self::assertStringContainsString('Nothing paid yet', $page->filter('#movements')->text());
        $page = $owner->request('GET', '/marketing/sales/ORD-0999');
        self::assertStringContainsString('This sale is in USD, so it is left out of every total.', $page->filter('.cl-flash-stack, .cl-page-section .cl-ui-notice')->text());
        $owner->request('GET', '/marketing/sales/ORD-4040');
        self::assertSame(404, $owner->getResponse()->getStatusCode(), 'No such sale.');
        $owner->request('GET', '/marketing/sales/example-org/ORD-1001');
        self::assertSame(404, $owner->getResponse()->getStatusCode(), 'A sale here is not a connected site\'s.');
    }

    public function testAConnectedSitesSaleIsMarkedAsSoldThereAndWhereSoldNarrowsTheList(): void
    {
        $this->shop();
        $site = $this->service(Sites::class)->add('Example Org', 'https://example.org', self::SECRET);
        $touch = (string) $this->service(Touches::class)->byId((int) $this->connection->fetchOne("SELECT id FROM cattophp_marketing_touches ORDER BY id DESC LIMIT 1"))?->reference;
        $now = new DateTimeImmutable();
        $lines = [new SaleLine('line:1', 'course', 'law-101', 'Labour law for employers', 'Course', 1, 16000)];
        self::assertSame(Sales::RECORDED, $this->service(Sales::class)->reported($site, new ReportedSale('sale_paid:EX-1001', 'sale_paid', $now, $touch, true, true, 'EX-1001', 'ZAR', 'paid',
            $now, $now, 16000, 0, 0, $lines)));
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/marketing/sales');
        self::assertSame('Paid Sold on Example Org', $this->cell($this->row($page, 'EX-1001'), 'State'));
        self::assertSame('/marketing/sales/' . $site->key . '/EX-1001', $this->row($page, 'EX-1001')->filter('td[data-label="Order"] a')->attr('href'));
        self::assertSame(['all', 'here', $site->key], $page->filter('input[name="where"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')), 'Where sold, with a connected site.');
        $page = $owner->request('GET', '/marketing/sales?where=here');
        self::assertNotContains('EX-1001', $this->orders($page));
        $page = $owner->request('GET', '/marketing/sales?where=' . $site->key);
        self::assertSame([['EX-1001'], 'R160'], [$this->orders($page), $this->totals($page)[7]]);
        $page = $owner->request('GET', '/marketing/sales/' . $site->key . '/EX-1001');
        self::assertContains('Sold on Example Org', $page->filter('.cl-page-head-badges .cl-ui-badge')->each(static fn (Crawler $badge): string => trim($badge->text())));
        self::assertCount(0, $page->filter('a:contains("Open the order")'), 'Its order is on the other site.');
        self::assertSame('A connected site', $this->cell($page->filter('#which-campaign tbody tr')->first(), 'How it was found'));
    }

    public function testACampaignsPageShowsItsSalesAndMoneyUnderTheLastOrTheFirstTouch(): void
    {
        $this->shop();
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/marketing/campaigns/spring-sale');
        self::assertSame('last', $page->filter('#campaign-counts input[checked]')->attr('value'), 'As the settings say.');
        self::assertSame(['0', 'R0', 'R0'], [$this->figure($page, 'Paying customers'), $this->figure($page, 'Net revenue'), $this->figure($page, 'Back for every R1')]);
        self::assertSame(['0', 'R0'], [$this->cell($page->filter('#publications tbody tr')->first(), 'Paid'), $this->cell($page->filter('#publications tbody tr')->first(), 'Net revenue')]);
        self::assertSame(['ORD-1002'], $page->filter('#orders tbody td[data-label="Order"]')->each(static fn (Crawler $cell): string => trim($cell->text())), 'Its waiting order, counted for its last touch.');
        self::assertStringContainsString('Showing 1 of 1 order: 1 waiting for payment.', $page->filter('#orders')->text());

        $page = $owner->request('GET', '/marketing/campaigns/spring-sale?counts=first');
        self::assertSame('first', $page->filter('#campaign-counts input[checked]')->attr('value'));
        self::assertSame(['1', 'R271', 'R1.08'], [$this->figure($page, 'Paying customers'), $this->figure($page, 'Net revenue'), $this->figure($page, 'Back for every R1')],
            'R271 of net revenue for its R250 spent.');
        self::assertSame(['1', 'R271'], [$this->cell($page->filter('#publications tbody tr')->first(), 'Paid'), $this->cell($page->filter('#publications tbody tr')->first(), 'Net revenue')]);
        self::assertSame(['ORD-1002', 'ORD-1001'], $page->filter('#orders tbody td[data-label="Order"]')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertStringContainsString('Showing 2 of 2 orders: 1 waiting for payment, 1 partly refunded.', $page->filter('#orders')->text());
        self::assertSame('/marketing/sales?campaign=spring-sale&counts=first&period=all&state=all', self::sortedQuery((string) $page->filter('#orders a:contains("See all its sales")')->attr('href')));
        $offers = [];
        foreach ($page->filter('#offers tbody tr') as $row) {
            $row = new Crawler($row);
            $offers[] = [$this->cell($row, 'Products and bundles'), $this->cell($row, 'Sold'), $this->cell($row, 'Net revenue')];
        }
        self::assertSame([['Rooibos tea, 100 bags Sold through it, not promoted', '2', 'R171'], ['Enamel mug Sold through it, not promoted', '1', 'R100']], $offers,
            'What its paid sales held, though it promotes nothing in particular.');

        $page = $owner->request('GET', '/marketing/campaigns/winter-warmers');
        self::assertSame(['1', 'R271', 'No spend'], [$this->figure($page, 'Paying customers'), $this->figure($page, 'Net revenue'), $this->figure($page, 'Back for every R1')]);
        self::assertSame(['Rooibos tea, 100 bags', '1', '0', '2', 'R171'], (function () use ($page): array {
            $row = $page->filter('#offers tbody tr')->first();

            return [$this->cell($row, 'Products and bundles'), $this->cell($row, 'Viewed'), $this->cell($row, 'Favourited'), $this->cell($row, 'Sold'), $this->cell($row, 'Net revenue')];
        })(), 'Viewed by someone who came by it; sold through it.');
        $this->campaigns()->create(new CampaignInput('Quiet autumn', 'quiet-autumn'));
        $page = $owner->request('GET', '/marketing/campaigns/quiet-autumn');
        self::assertStringContainsString('No orders yet', $page->filter('#orders')->text());
        self::assertSame('No spend', $this->figure($page, 'Back for every R1'));
    }

    public function testTheOverviewShowsTheLatestSalesWithinTheSetting(): void
    {
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/marketing');
        self::assertStringContainsString('No sale in the last 30 days', $page->filter('#latest-sales')->text());
        $this->shop();
        $old = new DateTimeImmutable('-40 days');
        $report = new SaleReport('ORD-0900', null, null, $old, 'ZAR', 15000, lines: [new SaleLine('item:1', 'product', 'mug-01', 'Enamel mug', 'Product', 1, 15000)]);
        $this->service(Sales::class)->paid($report, $old, 'Card');
        $page = $owner->request('GET', '/marketing');
        self::assertSame(['ORD-1003', 'ORD-1001', 'ORD-0999'], array_map(fn (string $href): string => basename($href), $page->filter('#latest-sales tbody td[data-label="Paid"] a')->each(static fn (Crawler $a): string => (string) $a->attr('href'))),
            'Paid in the last 30 days, the newest first; the one paid 40 days ago is not.');
        self::assertSame(['Ayanda Khumalo', 'Winter warmers', 'Email, November letter', 'R321'], (function () use ($page): array {
            $row = $page->filter('#latest-sales tbody tr')->eq(1);

            return [$this->cell($row, 'Customer'), $this->cell($row, 'Campaign'), $this->cell($row, 'Came through'), $this->cell($row, 'Amount')];
        })());
        $all = (string) $page->filter('#latest-sales a:contains("All sales")')->attr('href');
        self::assertMatchesRegularExpression('#^/marketing/sales\?period=dates&from=\d{4}-\d{2}-\d{2}$#', $all);
        self::assertSame(['ORD-1003', 'ORD-1001', 'ORD-0999'], $this->orders($owner->request('GET', $all)), 'All sales opens the same sales.');

        $settings = $this->service(Settings::class);
        $settings->set('MARKETING_LATEST_SALES', '60d');
        $page = $owner->request('GET', '/marketing');
        self::assertCount(4, $page->filter('#latest-sales tbody tr'), 'Within 60 days, the one 40 days ago too.');
        self::assertStringContainsString('Paid in the last 60 days', $page->filter('#latest-sales')->text());
    }

    public function testTheTemplatesHoldNoInlineStyleOrScriptAndEveryButtonHasItsIcon(): void
    {
        $templates = array_filter(TemplateContract::cattophpTemplates(), static fn (string $name): bool => in_array($name, ['@CattoPHP/marketing/sales.html.twig', '@CattoPHP/marketing/sales-row.html.twig',
            '@CattoPHP/marketing/sale.html.twig', '@CattoPHP/marketing/visitors.html.twig', '@CattoPHP/marketing/visitor.html.twig', '@CattoPHP/marketing/campaign.html.twig',
            '@CattoPHP/marketing/overview.html.twig'], true), ARRAY_FILTER_USE_KEY);
        self::assertCount(7, $templates);
        foreach ($templates as $name => $source) {
            $markup = (string) preg_replace('/\{#.*?#\}/s', '', $source);
            self::assertDoesNotMatchRegularExpression('/\sstyle\s*=|<style\b|<script\b|\son[a-z]+\s*=|javascript:/i', $markup, $name);
        }
        self::assertSame([], TemplateContract::duplicates($templates));
        self::assertSame([], TemplateContract::withoutIcon($templates));
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
        foreach (array_keys(TemplateContract::namedIcons($templates)) as $icon) {
            self::assertStringContainsString('`' . $icon . '`', $readme, $icon . ' is in the README\'s list of icons.');
        }
    }

    /**
     * The shop's sales, as its project reports them: ORD-1001 (Ayanda, by the computer that came first by the spring
     * sale's Facebook post two days ago and then by the winter email; tea and a mug, R321 paid by card, R50 of the mug
     * refunded), ORD-1002 (the gift box, waiting, from a phone that came by the spring sale), ORD-1003 (a mug, no
     * campaign, R150 paid, R40 charged back), ORD-1004 (a mug, cancelled) and ORD-0999 (a mug in USD, paid yesterday).
     */
    private function shop(): void
    {
        $campaigns = $this->campaigns();
        $publications = $this->service(Publications::class);
        $campaigns->create(new CampaignInput('Spring sale', 'spring-sale', destination: '/shop'));
        $campaigns->activate('spring-sale');
        $publications->create('spring-sale', new PublicationInput('facebook', cost: '250', place: 'Tea lovers group'));
        $campaigns->create(new CampaignInput('Winter warmers', 'winter-warmers', destination: '/shop', offers: ['product:tea-100']));
        $campaigns->activate('winter-warmers');
        $publications->create('winter-warmers', new PublicationInput('email', place: 'November letter'));
        $computer = $this->click('spring-sale', 'fb1', self::COMPUTER);
        $this->connection->executeStatement("UPDATE cattophp_marketing_touches SET at = at - interval '2 days'");
        $this->click('winter-warmers', 'em1', self::COMPUTER, $computer);
        $this->service(\CattoPHP\Marketing\Journey::class)->viewed(new \CattoPHP\Marketing\Offer('product', 'tea-100', 'Rooibos tea, 100 bags'), $this->request($computer, self::COMPUTER));
        $phone = $this->click('spring-sale', 'fb1', self::PHONE);
        $sales = $this->service(Sales::class);
        $now = new DateTimeImmutable();
        $tea = new SaleLine('item:1', 'product', 'tea-100', 'Rooibos tea, 100 bags', 'Product', 2, 19000, 24000, 1900);
        $mug = new SaleLine('item:2', 'product', 'mug-01', 'Enamel mug', 'Product', 1, 15000);
        $one = new SaleReport('ORD-1001', '1', null, $now, 'ZAR', 32100, lines: [$tea, $mug]);
        self::assertNotNull($sales->placed($one, $this->request($computer, self::COMPUTER)));
        self::assertNotNull($sales->paid($one, new DateTimeImmutable(), 'Card'));
        self::assertNotNull($sales->refunded('ORD-1001', 'refund:1', ['item:2' => 5000], new DateTimeImmutable(), 'Money back'));
        $box = new SaleLine('item:1', 'bundle', 'gift-box', 'Gift box', 'Bundle', 1, 32000, contains: ['product:tea-100', 'product:mug-01']);
        self::assertNotNull($sales->placed(new SaleReport('ORD-1002', null, null, new DateTimeImmutable(), 'ZAR', 32000, lines: [$box]), $this->request($phone, self::PHONE)));
        $three = new SaleReport('ORD-1003', '7', null, new DateTimeImmutable(), 'ZAR', 15000, lines: [$mug]);
        self::assertNotNull($sales->placed($three, $this->request(null, self::COMPUTER)));
        self::assertNotNull($sales->paid($three, new DateTimeImmutable(), 'EFT'));
        self::assertNotNull($sales->chargedBack('ORD-1003', 'chargeback:1', 4000, new DateTimeImmutable()));
        self::assertNotNull($sales->placed(new SaleReport('ORD-1004', null, null, new DateTimeImmutable(), 'ZAR', 15000, lines: [$mug]), $this->request(null, self::COMPUTER)));
        self::assertNotNull($sales->cancelled('ORD-1004', new DateTimeImmutable(), 'Not paid in time'));
        $yesterday = new DateTimeImmutable('-1 day');
        $dollars = new SaleReport('ORD-0999', null, null, $yesterday, 'USD', 15000, lines: [$mug]);
        self::assertNotNull($sales->paid($dollars, $yesterday, 'Card'));
        self::assertSame('ZAR', $sales->currency());
        unset($now);
    }

    /** A browser opening a campaign's tracking link: its visitor's token. */
    private function click(string $campaign, string $publication, string $agent, ?string $token = null): string
    {
        $links = $this->service(TrackingLinks::class);
        $request = $this->request($token, $agent, '/c/' . $campaign . '/' . $publication);
        $opening = $links->open($campaign, $publication, $request) ?? throw new \LogicException('No such link.');

        $cookie = $links->recordOpening($opening, $request)->cookie?->getValue();

        return $token ?? (string) $cookie;
    }

    private function request(?string $token, string $agent, string $path = '/checkout'): Request
    {
        return Request::create($path, 'GET', [], $token === null ? [] : [Visitors::COOKIE => $token], [], ['HTTP_USER_AGENT' => $agent, 'REMOTE_ADDR' => '198.51.100.7']);
    }

    /** @return list<string> the orders the list shows, in its order */
    private function orders(Crawler $page): array
    {
        return $page->filter('#sales-results tbody td[data-label="Order"]')->each(static fn (Crawler $cell): string => trim($cell->text()));
    }

    /** @return list<string> the totals' figures */
    private function totals(Crawler $page): array
    {
        return $page->filter('#sales-totals .cl-ui-stat-value')->each(static fn (Crawler $value): string => trim($value->text()));
    }

    private function figure(Crawler $page, string $label): string
    {
        foreach ($page->filter('#figures .cl-ui-stat-card') as $card) {
            $card = new Crawler($card);
            if (trim($card->filter('.cl-ui-stat-label')->text()) === $label) {
                return trim($card->filter('.cl-ui-stat-value')->text());
            }
        }

        return 'none: ' . $label;
    }

    private function row(Crawler $page, string $order): Crawler
    {
        foreach ($page->filter('#sales-results tbody tr') as $row) {
            $row = new Crawler($row);
            if (trim($row->filter('td[data-label="Order"]')->text()) === $order) {
                return $row;
            }
        }
        self::fail($order . ' is not listed.');
    }

    private function cell(Crawler $row, string $label): string
    {
        $cell = $row->filter('td[data-label="' . $label . '"]');

        return $cell->count() === 0 ? 'none: ' . $label : trim((string) preg_replace('/\s+/', ' ', $cell->text()));
    }

    /** @return list<list<string>> the CSV's rows */
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

    private static function sortedQuery(string $href): string
    {
        [$path, $query] = explode('?', $href, 2) + [1 => ''];
        parse_str($query, $values);
        ksort($values);

        return $path . '?' . http_build_query($values);
    }

    /** @param list<string> $permissions */
    private function role(string $key, array $permissions, string $account): void
    {
        $roles = $this->service(Roles::class);
        $roles->save($key, 'Marketing helper', '', $permissions);
        $roles->grant($account, $key);
    }

    private function campaigns(): Campaigns
    {
        return $this->service(Campaigns::class);
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
