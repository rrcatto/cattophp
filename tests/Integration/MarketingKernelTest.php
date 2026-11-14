<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Access\PermissionCatalogue;
use CattoPHP\Account\Records\MarketingRecords;
use CattoPHP\Marketing\Pictures;
use CattoPHP\Marketing\Adverts;
use CattoPHP\Marketing\CampaignFigures;
use CattoPHP\Marketing\CampaignInput;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Channels;
use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\Journey;
use CattoPHP\Marketing\Landing\GenericLanding;
use CattoPHP\Marketing\Landing\LandingPages;
use CattoPHP\Marketing\Landing\LandingPresses;
use CattoPHP\Marketing\Landing\LandingProject;
use CattoPHP\Marketing\Landing\LandingSections;
use CattoPHP\Marketing\Landing\LandingTemplates;
use CattoPHP\Marketing\Landing\LandingVersions;
use CattoPHP\Marketing\Landing\PictureSources;
use CattoPHP\Marketing\Landing\SectionKinds;
use CattoPHP\Marketing\MarketingActionWords;
use CattoPHP\Marketing\MarketingPermissions;
use CattoPHP\Marketing\MarketingProject;
use CattoPHP\Marketing\MarketingSettings;
use CattoPHP\Marketing\MarketingSubjectLinks;
use CattoPHP\Marketing\MarketingWiring;
use CattoPHP\Marketing\Journey\JourneyWiring;
use CattoPHP\Marketing\Mail\MailWiring;
use CattoPHP\Marketing\Pages\PagesWiring;
use CattoPHP\Marketing\Sales\SalesWiring;
use CattoPHP\Marketing\Sites\SitesWiring;
use CattoPHP\Marketing\PublicationInput;
use CattoPHP\Marketing\Publications;
use CattoPHP\Marketing\Spend;
use CattoPHP\Marketing\Touch;
use CattoPHP\Marketing\Touches;
use CattoPHP\Marketing\TrackingLinks;
use CattoPHP\Marketing\Visitors;
use CattoPHP\Scheduler\Scheduler;
use CattoPHP\Settings\SettingsCatalogue;
use CattoPHP\Tests\Kernel\App\ExampleMarketingProject;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\RecordingLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * The Campaign Marketing Manager as a project gets it: wired from its configuration, and its public tracking links end
 * to end through the kernel: a person, a repeat, a link preview, a browser asking not to be tracked, an unknown code, a
 * paused campaign, the query carried on, and a failure while recording that still forwards the visitor.
 */
final class MarketingKernelTest extends DatabaseTestCase
{
    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:146.0) Gecko/20100101 Firefox/146.0';

    private string $folder;
    private TestKernel $kernel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = Foundations::folder();
        $this->kernel = new TestKernel($this->folder, (string) getenv('CATTOPHP_TEST_DATABASE_URL'), scheduler: true, marketing: true);
        $this->kernel->boot();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        Foundations::remove($this->folder);
        restore_exception_handler();
        parent::tearDown();
    }

    public function testTheModuleIsWiredFromItsConfiguration(): void
    {
        $container = $this->container();
        foreach ([Campaigns::class, Channels::class, Adverts::class, Publications::class, Spend::class, Visitors::class, Touches::class, TrackingLinks::class, CampaignFigures::class,
            MarketingSettings::class, Pictures::class, MarketingActionWords::class, MarketingRecords::class] as $service) {
            self::assertInstanceOf($service, $container->get($service), $service);
        }
        self::assertInstanceOf(ExampleMarketingProject::class, $container->get(MarketingProject::class), 'The project\'s own part, by its service id.');
        $settings = $container->get(SettingsCatalogue::class);
        self::assertInstanceOf(SettingsCatalogue::class, $settings);
        self::assertSame('Campaign Marketing Manager', $settings->group('marketing')->label);
        $permissions = $container->get(PermissionCatalogue::class);
        self::assertInstanceOf(PermissionCatalogue::class, $permissions);
        foreach ([MarketingPermissions::VIEW, MarketingPermissions::MANAGE, MarketingPermissions::PEOPLE, MarketingPermissions::SETTINGS] as $permission) {
            self::assertTrue($permissions->has($permission), $permission);
        }
        $scheduler = $container->get(Scheduler::class);
        self::assertInstanceOf(Scheduler::class, $scheduler);
        self::assertNotNull($scheduler->task('marketing_tidy_up'), 'The nightly tidy-up, with the scheduler on.');
        $router = $container->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        self::assertSame(['/c/{campaign}', ['GET']], [$router->getRouteCollection()->get('cattophp_marketing_link')?->getPath(), $router->getRouteCollection()->get('cattophp_marketing_link')?->getMethods()]);
        self::assertSame('/c/{campaign}/{publication}', $router->getRouteCollection()->get('cattophp_marketing_link_publication')?->getPath());
        $pictures = $container->get(Pictures::class);
        self::assertInstanceOf(Pictures::class, $pictures);
    }

    public function testItsWiringIsItsOwnAndAProjectsExclusionsAreTheStartsOfPaths(): void
    {
        foreach ([MarketingWiring::class, JourneyWiring::class, MailWiring::class, SalesWiring::class, SitesWiring::class, PagesWiring::class] as $wiring) {
            self::assertTrue(method_exists($wiring, 'register'), $wiring);
        }
        $container = $this->container();
        self::assertInstanceOf(MarketingSubjectLinks::class, $container->get(MarketingSubjectLinks::class), 'The activity log\'s links, as before.');
        foreach (PagesWiring::CONTROLLERS as $controller) {
            self::assertTrue($container->has($controller), $controller);
        }

        $folder = Foundations::folder();
        $kernel = new TestKernel($folder, (string) getenv('CATTOPHP_TEST_DATABASE_URL'), marketing: true, marketingConfig: ['returns' => false, 'returns_excluded' => ['admin']]);
        try {
            $kernel->boot();
            self::fail('A project with an exclusion that is not the start of a path started.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame('Each of cattophp.marketing.returns_excluded is the start of a path, such as /admin: "admin" is not.', $refused->getMessage());
        } finally {
            Foundations::remove($folder);
            restore_exception_handler();
        }
    }

    public function testTheLandingPagesAreWiredWithCattoPHPsKindsSourcesAndTemplates(): void
    {
        $container = $this->container();
        foreach ([LandingPages::class, LandingSections::class, LandingVersions::class, LandingTemplates::class, SectionKinds::class, PictureSources::class, Journey::class, LandingPresses::class,
            Events::class] as $service) {
            self::assertInstanceOf($service, $container->get($service), $service);
        }
        $kinds = $container->get(SectionKinds::class);
        self::assertInstanceOf(SectionKinds::class, $kinds);
        self::assertSame(['hero', 'words', 'picture', 'video', 'offers', 'audience', 'outcomes', 'benefits', 'countdown', 'faq', 'cta'], array_keys($kinds->all()), 'In the order of the Add a section cards.');
        self::assertSame('Products and bundles', $kinds->get('offers')?->label(), 'The offers section has the project\'s own heading.');
        $sources = $container->get(PictureSources::class);
        self::assertInstanceOf(PictureSources::class, $sources);
        self::assertSame(['upload', 'offer'], array_keys($sources->all()));
        $templates = $container->get(LandingTemplates::class);
        self::assertInstanceOf(LandingTemplates::class, $templates);
        self::assertSame(['several-offers', 'special-with-end-date'], array_map(static fn($t): string => $t->key, $templates->builtIn()));
        self::assertInstanceOf(GenericLanding::class, $container->get(LandingProject::class), 'Without the project\'s own part.');
        self::assertSame('landing', $this->kernel->getContainer()->getParameter('cattophp.marketing.landing_prefix'));

        $campaigns = $container->get(Campaigns::class);
        $pages = $container->get(LandingPages::class);
        self::assertInstanceOf(Campaigns::class, $campaigns);
        self::assertInstanceOf(LandingPages::class, $pages);
        $campaigns->create(new CampaignInput('Spring sale', 'spring-sale', offers: ['product:tea-100']));
        $page = $pages->createForCampaign('spring-sale')->page;
        self::assertSame('/c/spring-sale', $pages->address($page));
        $offer = $pages->createForOffer('product', 'mug-01')->page;
        self::assertSame('/landing/enamel-mug', $pages->address($offer));
        $history = $this->connection->fetchFirstColumn("SELECT action FROM cattophp_activity WHERE subject_type = 'marketing_landing_page' ORDER BY id");
        self::assertSame(['marketing.landing_page.created', 'marketing.landing_page.created'], $history, 'In the activity log, by the page\'s reference.');
    }

    public function testAPersonIsForwardedWithACookieAndTheQueryCarriedAndARepeatIsTheSameVisit(): void
    {
        $this->campaign();
        $browser = $this->browser();
        $browser->request('GET', '/c/spring-sale/fb1?colour=red&utm_source=social&fbclid=abc', server: ['HTTP_USER_AGENT' => self::BROWSER, 'HTTP_REFERER' => 'https://social.example.com/post']);
        $response = $browser->getResponse();
        self::assertSame([302, '/shop/tea?colour=red'], [$response->getStatusCode(), $response->headers->get('Location')]);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame([Visitors::COOKIE, true, 'lax'], [$cookies[0]->getName(), $cookies[0]->isHttpOnly(), $cookies[0]->getSameSite()]);
        $browser->request('GET', '/c/spring-sale/fb1', server: ['HTTP_USER_AGENT' => self::BROWSER]);
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        $browser->request('GET', '/c/Spring-Sale', server: ['HTTP_USER_AGENT' => self::BROWSER]);
        self::assertSame('/shop', $browser->getResponse()->headers->get('Location'), 'The campaign\'s own link, its code in any case.');
        $touches = $this->touches();
        self::assertSame([[true, null, 'social', 'social.example.com'], [false, 'same_visit', 'facebook', 'localhost'], [true, null, '', 'localhost']],
            array_map(static fn(Touch $t): array => [$t->counted, $t->notCountedBecause, $t->utm['utm_source'] ?? $t->source, $t->referrerHost], $touches));
        self::assertSame(1, count(array_unique(array_map(static fn(Touch $t): ?int => $t->visitorId, $touches))), 'One visitor throughout.');
    }

    public function testALinkPreviewAndABrowserAskingNotToBeTrackedAreForwardedWithNoCookie(): void
    {
        $this->campaign();
        $preview = $this->browser();
        $preview->request('GET', '/c/spring-sale/fb1', server: ['HTTP_USER_AGENT' => 'WhatsApp/2.24.1.6 A']);
        self::assertSame([302, []], [$preview->getResponse()->getStatusCode(), $preview->getResponse()->headers->getCookies()]);
        $private = $this->browser();
        $private->request('GET', '/c/spring-sale/fb1', server: ['HTTP_USER_AGENT' => self::BROWSER, 'HTTP_DNT' => '1']);
        self::assertSame([302, []], [$private->getResponse()->getStatusCode(), $private->getResponse()->headers->getCookies()]);
        self::assertSame([['preview', false, true, false], ['person', true, true, true]],
            array_map(static fn(Touch $t): array => [$t->agent, $t->counted, $t->visitorId === null, $t->ip === null], $this->touches()), 'Neither has a visitor; the private browser\'s address is not kept.');
    }

    public function testAnUnknownCodeIsTheSitesOrdinaryNotFound(): void
    {
        $this->campaign();
        $browser = $this->browser();
        foreach (['/c/no-such-campaign', '/c/spring-sale/zz9', '/c/x'] as $path) {
            $browser->request('GET', $path, server: ['HTTP_USER_AGENT' => self::BROWSER]);
            self::assertSame(404, $browser->getResponse()->getStatusCode(), $path);
        }
        self::assertSame([], $this->touches());
    }

    public function testAPausedCampaignStillForwardsAndItsClickIsSetAside(): void
    {
        $this->campaign();
        $campaigns = $this->container()->get(Campaigns::class);
        self::assertInstanceOf(Campaigns::class, $campaigns);
        $campaigns->pause('spring-sale');
        $browser = $this->browser();
        $browser->request('GET', '/c/spring-sale/fb1', server: ['HTTP_USER_AGENT' => self::BROWSER]);
        self::assertSame([302, '/shop/tea'], [$browser->getResponse()->getStatusCode(), $browser->getResponse()->headers->get('Location')]);
        self::assertSame([[false, 'paused']], array_map(static fn(Touch $t): array => [$t->counted, $t->notCountedBecause], $this->touches()));
    }

    public function testAFailureWhileRecordingIsLoggedAndTheVisitorForwardedAllTheSame(): void
    {
        $this->campaign();
        $this->connection->executeStatement('DROP TABLE cattophp_marketing_touches CASCADE');
        $browser = $this->browser();
        $browser->request('GET', '/c/spring-sale/fb1?colour=red', server: ['HTTP_USER_AGENT' => self::BROWSER]);
        $response = $browser->getResponse();
        self::assertSame([302, '/shop/tea?colour=red', []], [$response->getStatusCode(), $response->headers->get('Location'), $response->headers->getCookies()]);
        $logger = $this->container()->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        self::assertContains('error: A tracking link\'s opening could not be recorded; the visitor was forwarded all the same.', $logger->lines);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_marketing_visitors'), 'Nothing half recorded.');
    }

    /** Spring sale, active, leading to /shop, with fb1 on Facebook leading to /shop/tea. */
    private function campaign(): void
    {
        $container = $this->container();
        $campaigns = $container->get(Campaigns::class);
        $publications = $container->get(Publications::class);
        self::assertInstanceOf(Campaigns::class, $campaigns);
        self::assertInstanceOf(Publications::class, $publications);
        $campaigns->create(new CampaignInput('Spring sale', 'spring-sale', destination: '/shop'));
        $campaigns->activate('spring-sale');
        $publications->create('spring-sale', new PublicationInput('facebook', destination: '/shop/tea'));
    }

    /** @return list<Touch> the spring sale's openings, oldest first */
    private function touches(): array
    {
        $touches = $this->container()->get(Touches::class);
        $campaigns = $this->container()->get(Campaigns::class);
        self::assertInstanceOf(Touches::class, $touches);
        self::assertInstanceOf(Campaigns::class, $campaigns);

        return array_reverse($touches->forCampaign($campaigns->require('spring-sale')->id));
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
