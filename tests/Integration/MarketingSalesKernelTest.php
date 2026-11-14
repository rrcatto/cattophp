<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Account\Records\MarketingRecords;
use CattoPHP\Marketing\CampaignInput;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\MarketingProject;
use CattoPHP\Marketing\Sales\SaleAttribution;
use CattoPHP\Marketing\Sales\SaleFilter;
use CattoPHP\Marketing\Sales\SaleLine;
use CattoPHP\Marketing\Sales\SaleReport;
use CattoPHP\Marketing\Sales\Sales;
use CattoPHP\Marketing\TrackingLinks;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\MarketingSalesShop;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Sales as a project gets them from the container: wired with the module, the current request's browser found when a
 * service with no request of its own reports an order, the project's three methods for organisations, its people's
 * search and a publication's source, and forgetting an account taking it off its sales.
 */
final class MarketingSalesKernelTest extends DatabaseTestCase
{
    private string $folder;
    private TestKernel $kernel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = Foundations::folder();
        $this->kernel = new TestKernel($this->folder, (string) getenv('CATTOPHP_TEST_DATABASE_URL'), marketing: true);
        $this->kernel->boot();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        Foundations::remove($this->folder);
        restore_exception_handler();
        parent::tearDown();
    }

    public function testSalesAreWiredAndFindTheCurrentRequestsBrowser(): void
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $sales = $container->get(Sales::class);
        self::assertInstanceOf(Sales::class, $sales);
        self::assertInstanceOf(SaleAttribution::class, $container->get(SaleAttribution::class));
        $campaigns = $container->get(Campaigns::class);
        self::assertInstanceOf(Campaigns::class, $campaigns);
        $campaign = $campaigns->activate($campaigns->create(new CampaignInput('Spring sale', 'spring-sale'))->code);
        $links = $container->get(TrackingLinks::class);
        self::assertInstanceOf(TrackingLinks::class, $links);
        $click = Request::create('/c/spring-sale', 'GET', [], [], [], ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_USER_AGENT' => MarketingSalesShop::PERSON]);
        $opening = $links->recordOpening($links->open('spring-sale', null, $click) ?? throw new \LogicException('The link opens.'), $click);

        $requests = $container->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push(Request::create('/checkout', 'POST', [], ['cattophp_visitor' => (string) $opening->cookie?->getValue()], [], ['HTTP_USER_AGENT' => MarketingSalesShop::PERSON]));
        $report = new SaleReport('ORD-5001', '1', 'ORG-0001', new \DateTimeImmutable(), 'ZAR', 9500, lines: [new SaleLine('item:1', 'product', 'tea-100', 'Rooibos tea, 100 bags', 'Product', 1, 9500)]);
        $placed = $sales->placed($report);
        self::assertSame([$opening->visitorId, $campaign->id], [$placed?->visitorId, $placed?->lastCampaignId]);
        self::assertNotNull($sales->paid($report, new \DateTimeImmutable(), 'Card'));
        self::assertSame(1, $sales->count(new SaleFilter(organisation: 'ORG-0001')));

        $project = $container->get(MarketingProject::class);
        self::assertInstanceOf(MarketingProject::class, $project);
        self::assertSame(['Fynbos Traders', 'ORG-0001'], [$project->organisation('ORG-0001')['label'] ?? null, $project->organisation('ORG-0001')['reference'] ?? null]);
        self::assertNull($project->organisation('ORG-0002'));
        self::assertSame([['1'], ['1'], []], [$project->accountsMatching('Khumalo'), $project->accountsMatching('ayanda@example.com'), $project->accountsMatching('')]);
        self::assertSame('/admin/mailing/messages/12', $project->publicationSource('mailing', '12')['href'] ?? null);
        self::assertNull($project->publicationSource('poster', '12'));

        $records = $container->get(MarketingRecords::class);
        self::assertInstanceOf(MarketingRecords::class, $records);
        self::assertSame(1, $records->forgetAccount('1', null)['unnamed']['campaign sales'] ?? null);
        self::assertNull($sales->get('ORD-5001')?->account);
    }
}
