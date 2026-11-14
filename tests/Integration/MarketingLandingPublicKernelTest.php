<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Marketing\CampaignInput;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\Landing\LandingPage;
use CattoPHP\Marketing\Landing\LandingPages;
use CattoPHP\Marketing\Landing\LandingSection;
use CattoPHP\Marketing\Landing\LandingSections;
use CattoPHP\Marketing\Landing\LandingSettingsInput;
use CattoPHP\Marketing\Landing\NotRunning;
use CattoPHP\Marketing\Pictures;
use CattoPHP\Marketing\PublicationInput;
use CattoPHP\Marketing\Publications;
use CattoPHP\Marketing\Touch;
use CattoPHP\Marketing\Touches;
use CattoPHP\Marketing\Visitors;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\RecordingTransport;
use CattoPHP\Tests\Support\SamplePictures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The landing pages' public addresses through the kernel, as a project with GenericLanding (no landing part of its own)
 * gets them: a campaign's page drawn at its tracking links, its click and its visit kept apart; link previews, Do Not
 * Track; what a page does while its campaign is not running; a draft forwarding; an offer's page; the picture and press
 * addresses.
 */
final class MarketingLandingPublicKernelTest extends DatabaseTestCase
{
    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:146.0) Gecko/20100101 Firefox/146.0';

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

    public function testARunningCampaignsBareLinkAndPublicationLinkEachDrawThePageWithOneTouchAndOneVisit(): void
    {
        $page = $this->publishedCampaignPage();
        $browser = $this->browser();
        $browser->request('GET', '/c/spring-sale', server: ['HTTP_USER_AGENT' => self::BROWSER]);
        $response = $browser->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        self::assertSame([Visitors::COOKIE], array_map(static fn($c): string => $c->getName(), $response->headers->getCookies()), 'The tracking link gives its cookie with the page.');
        $html = $this->crawler($browser);
        self::assertSame(['Spring sale'], $html->filter('h1')->each(static fn(Crawler $h): string => trim($h->text())), 'The hero\'s only h1, the campaign\'s name with no headline written.');
        self::assertSame(1, $html->filter('section.cl-page-head.cl-landing-hero')->count());
        self::assertSame(['Rooibos tea, 100 bags', 'Enamel mug'], $html->filter('.cl-landing-offer-name')->each(static fn(Crawler $n): string => trim($n->text())));
        self::assertSame(['offers', 'faq', 'cta'], $html->filter('div.cl-landing-sections section.cl-landing-section')->each(static fn(Crawler $s): string => (string) $s->attr('id')));
        self::assertSame('https://example.com/c/spring-sale', $html->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame('noindex', $html->filter('meta[name="robots"]')->attr('content'));

        $other = $this->browser();
        $other->request('GET', '/c/spring-sale/fb1?colour=red', server: ['HTTP_USER_AGENT' => self::BROWSER]);
        self::assertSame(200, $other->getResponse()->getStatusCode(), 'A publication with no destination of its own leads to the campaign\'s page.');
        self::assertSame('https://example.com/c/spring-sale', $this->crawler($other)->filter('link[rel="canonical"]')->attr('href'), 'The canonical address whatever link was opened.');

        $touches = $this->touches();
        self::assertCount(2, $touches);
        self::assertSame([['/c/spring-sale', true], ['/c/spring-sale', true]], array_map(static fn(Touch $t): array => [$t->landing, $t->counted], $touches));
        $visits = $this->events();
        self::assertSame([[Events::LANDING_OPENED, $touches[0]->id, $page->id], [Events::LANDING_OPENED, $touches[1]->id, $page->id]],
            array_map(static fn(array $e): array => [$e['kind'], $e['touch_id'], $e['landing_page_id']], $visits), 'One visit for each click, each against its own touch.');
        self::assertNotNull($visits[1]['publication_id'], 'The publication link\'s visit names its publication.');

        $tea = $this->browser();
        $tea->request('GET', '/c/spring-sale/fb2', server: ['HTTP_USER_AGENT' => self::BROWSER]);
        self::assertSame([302, '/shop/tea'], [$tea->getResponse()->getStatusCode(), $tea->getResponse()->headers->get('Location')], 'A publication with its own destination still forwards.');
        self::assertCount(3, $this->touches());
        self::assertCount(2, $this->events(), 'A link that forwards elsewhere has a click and no visit.');
    }

    public function testALinkPreviewGetsThePageWithItsShareTagsItsTouchSetAsideAndNoVisit(): void
    {
        $this->publishedCampaignPage();
        $preview = $this->browser();
        $preview->request('GET', '/c/spring-sale/fb1', server: ['HTTP_USER_AGENT' => 'WhatsApp/2.24.1.6 A']);
        self::assertSame([200, []], [$preview->getResponse()->getStatusCode(), $preview->getResponse()->headers->getCookies()]);
        $html = $this->crawler($preview);
        self::assertSame('Spring sale', $html->filter('meta[property="og:title"]')->attr('content'));
        self::assertSame('https://example.com/c/spring-sale', $html->filter('meta[property="og:url"]')->attr('content'));
        self::assertSame([['preview', false]], array_map(static fn(Touch $t): array => [$t->agent, $t->counted], $this->touches()));
        self::assertSame([], $this->events());
    }

    public function testABrowserAskingNotToBeTrackedSeesThePageWithNoCookieAndItsVisitNamesNobody(): void
    {
        $this->publishedCampaignPage();
        $browser = $this->browser();
        $browser->request('GET', '/c/spring-sale', server: ['HTTP_USER_AGENT' => self::BROWSER, 'HTTP_DNT' => '1']);
        self::assertSame([200, []], [$browser->getResponse()->getStatusCode(), $browser->getResponse()->headers->getCookies()]);
        $visits = $this->events();
        self::assertCount(1, $visits);
        self::assertSame([null, true], [$visits[0]['visitor_id'], (bool) $visits[0]['do_not_track']]);
    }

    /** @return iterable<string,array{string,string}> each state a campaign can be not running in, against each choice */
    public static function notRunning(): iterable
    {
        foreach (['not_started', 'paused', 'ended'] as $state) {
            foreach ([NotRunning::KEEP, NotRunning::NOTE, NotRunning::FORWARD] as $choice) {
                yield $state . ', ' . $choice => [$state, $choice];
            }
        }
    }

    #[DataProvider('notRunning')]
    public function testACampaignThatIsNotRunning(string $state, string $choice): void
    {
        $page = $this->publishedCampaignPage();
        $pages = $this->service(LandingPages::class, LandingPages::class);
        $sections = $this->service(LandingSections::class, LandingSections::class);
        $hero = $this->section($page, 'hero');
        $sections->save($page->reference, $hero->reference, ['headline' => 'Spring, 10% off', 'not_running_headline' => 'Back next spring', 'not_running_text' => 'Our specials return.']);
        $cta = $this->section($page, 'cta');
        $sections->shownWhen($page->reference, $cta->reference, 'running');
        $words = $sections->add($page->reference, 'words');
        $sections->save($page->reference, $words->reference, ['heading' => 'Between specials', 'text' => 'Our tea is here all year.']);
        $sections->show($page->reference, $words->reference);
        $sections->shownWhen($page->reference, $words->reference, 'not_running');
        $pages->saveSettings($page->reference, new LandingSettingsInput(notRunning: $choice, notRunningTo: $choice === NotRunning::FORWARD ? '/offers' : ''));
        $campaigns = $this->service(Campaigns::class, Campaigns::class);
        match ($state) {
            'not_started' => $campaigns->change('spring-sale', new CampaignInput('Spring sale', 'spring-sale', startsAt: '2099-01-01', destination: '/shop', offers: ['product:tea-100', 'product:mug-01'])),
            'paused' => $campaigns->pause('spring-sale'),
            default => $campaigns->change('spring-sale', new CampaignInput('Spring sale', 'spring-sale', startsAt: '2020-01-01', endsAt: '2020-01-31', destination: '/shop', offers: ['product:tea-100', 'product:mug-01'])),
        };

        $browser = $this->browser();
        $browser->request('GET', '/c/spring-sale', server: ['HTTP_USER_AGENT' => self::BROWSER]);
        if ($choice === NotRunning::FORWARD) {
            self::assertSame([302, '/offers'], [$browser->getResponse()->getStatusCode(), $browser->getResponse()->headers->get('Location')]);
            self::assertSame(['/offers'], array_map(static fn(Touch $t): string => $t->landing, $this->touches()), 'The touch says where it led.');
            self::assertSame([], $this->events(), 'Forwarded elsewhere: a click and no visit.');

            return;
        }
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $html = $this->crawler($browser);
        self::assertSame('Back next spring', trim($html->filter('h1')->text()), 'The hero\'s words for while the campaign is not running.');
        self::assertStringContainsString('Our specials return.', $html->filter('.cl-landing-hero')->text());
        self::assertSame(['offers', 'faq', 'words'], $html->filter('section.cl-landing-section')->each(static fn(Crawler $s): string => (string) $s->attr('id')),
            'The call to action shown only while it runs is left out; the words shown only while it is not are in.');
        $note = $html->filter('#offers .cl-ui-notice');
        $expected = match ($state) {
            'not_started' => 'This special starts on 1 January 2099.',
            'paused' => 'This special is not available at the moment.',
            default => 'This special ended on 31 January 2020.',
        };
        if ($choice === NotRunning::NOTE) {
            self::assertSame($expected, trim($note->text()), 'The note above the offers.');
        } else {
            self::assertSame(0, $note->count(), 'Kept without a note.');
        }
        self::assertCount(1, $this->events(), 'Drawn: its visit is recorded.');
    }

    public function testADraftPageForwardsAsTheLinkAlwaysDidAndSoDoesADraftCampaignsPage(): void
    {
        $page = $this->publishedCampaignPage();
        $this->service(LandingPages::class, LandingPages::class)->unpublish($page->reference);
        $browser = $this->browser();
        $browser->request('GET', '/c/spring-sale', server: ['HTTP_USER_AGENT' => self::BROWSER]);
        self::assertSame([302, '/shop'], [$browser->getResponse()->getStatusCode(), $browser->getResponse()->headers->get('Location')]);
        self::assertSame([], $this->events());

        $campaigns = $this->service(Campaigns::class, Campaigns::class);
        $campaigns->create(new CampaignInput('Autumn sale', 'autumn-sale', destination: '/autumn', offers: ['product:tea-100']));
        $pages = $this->service(LandingPages::class, LandingPages::class);
        $draft = $pages->createForCampaign('autumn-sale')->page;
        $this->answerTheQuestions($draft);
        $pages->publish($draft->reference);
        $browser->request('GET', '/c/autumn-sale', server: ['HTTP_USER_AGENT' => self::BROWSER]);
        self::assertSame([302, '/autumn'], [$browser->getResponse()->getStatusCode(), $browser->getResponse()->headers->get('Location')], 'A draft campaign\'s page is never public.');
    }

    public function testUnknownAddressesAreTheSitesOrdinaryNotFound(): void
    {
        $page = $this->publishedCampaignPage();
        $browser = $this->browser();
        foreach (['/c/no-such-campaign', '/landing/no-such-page', '/landing/no-such-page/picture/abc.png', '/landing/no-such-page/go/abcd1234-1', '/c/no-such-campaign/go/abcd1234-1',
            '/c/spring-sale/go/' . $page->reference . '-99', '/c/spring-sale/go/zzzz9999-1', '/c/spring-sale/picture/' . str_repeat('a', 64) . '.png'] as $path) {
            $browser->request('GET', $path, server: ['HTTP_USER_AGENT' => self::BROWSER]);
            self::assertSame(404, $browser->getResponse()->getStatusCode(), $path);
        }
        self::assertSame([], $this->events());
    }

    public function testAnOffersPageIsDrawnOnlyWhilePublishedAndGivesNoCookie(): void
    {
        $pages = $this->service(LandingPages::class, LandingPages::class);
        $page = $pages->createForOffer('product', 'mug-01')->page;
        $this->answerTheQuestions($page);
        $browser = $this->browser();
        $browser->request('GET', '/landing/enamel-mug', server: ['HTTP_USER_AGENT' => self::BROWSER]);
        self::assertSame(404, $browser->getResponse()->getStatusCode(), 'A draft is not found.');
        $pages->publish($page->reference);
        $browser->request('GET', '/landing/enamel-mug', server: ['HTTP_USER_AGENT' => self::BROWSER]);
        $response = $browser->getResponse();
        self::assertSame([200, []], [$response->getStatusCode(), $response->headers->getCookies()]);
        $html = $this->crawler($browser);
        self::assertSame('Enamel mug', trim($html->filter('h1.cl-landing-headline')->text()));
        self::assertSame('https://example.com/landing/enamel-mug', $html->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame(0, $html->filter('meta[name="robots"]')->count(), 'An offer\'s page is indexed as the site\'s mode says.');
        self::assertSame(['Enamel mug'], $html->filter('.cl-landing-offer-name')->each(static fn(Crawler $n): string => trim($n->text())));
        self::assertSame([[Events::LANDING_OPENED, null, $page->id, 'product', 'mug-01']],
            array_map(static fn(array $e): array => [$e['kind'], $e['touch_id'], $e['landing_page_id'], $e['offer_type'], $e['offer_reference']], $this->events()));
        $pages->unpublish($page->reference);
        $browser->request('GET', '/landing/enamel-mug', server: ['HTTP_USER_AGENT' => self::BROWSER]);
        self::assertSame(404, $browser->getResponse()->getStatusCode(), 'Taken down: not found.');
    }

    public function testThePictureAddressServesOnlyAPictureThePageNamesWithItsHeadersAndCaching(): void
    {
        $page = $this->publishedCampaignPage();
        $pictures = $this->service(Pictures::class, Pictures::class);
        $named = $pictures->store(SamplePictures::file($this->folder, SamplePictures::PNG, 'hero.tmp'), 1_000_000);
        $other = $pictures->store(SamplePictures::file($this->folder, SamplePictures::JPEG, 'other.tmp'), 1_000_000);
        $sections = $this->service(LandingSections::class, LandingSections::class);
        $sections->save($page->reference, $this->section($page, 'hero')->reference, ['picture' => ['source' => 'upload', 'ref' => $named]]);

        $browser = $this->browser();
        $browser->request('GET', '/c/spring-sale', server: ['HTTP_USER_AGENT' => self::BROWSER]);
        $html = $this->crawler($browser);
        self::assertSame('/c/spring-sale/picture/' . $named, $html->filter('.cl-landing-picture-head img')->attr('src'));
        self::assertSame('https://example.com/c/spring-sale/picture/' . $named, $html->filter('meta[property="og:image"]')->attr('content'), 'The hero\'s picture is the share picture.');

        $browser->request('GET', '/c/spring-sale/picture/' . $named);
        $response = $browser->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame("default-src 'none'; style-src 'unsafe-inline'; sandbox", $response->headers->get('Content-Security-Policy'));
        self::assertStringStartsWith('inline; filename=picture.png', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('max-age=3600, public', $response->headers->get('Cache-Control'), 'MARKETING_LANDING_PICTURE_CACHED, an hour to start.');
        $browser->request('GET', '/c/spring-sale/picture/' . $other);
        self::assertSame(404, $browser->getResponse()->getStatusCode(), 'A picture the page does not name.');

        $this->service(LandingPages::class, LandingPages::class)->unpublish($page->reference);
        $browser->request('GET', '/c/spring-sale/picture/' . $named);
        self::assertSame(404, $browser->getResponse()->getStatusCode(), 'Before the page is public, not for visitors.');
        $owner = $this->signedIn('owner@example.com');
        $owner->request('GET', '/c/spring-sale/picture/' . $named);
        self::assertSame(200, $owner->getResponse()->getStatusCode(), 'Its editors see it.');
        self::assertSame([true, true, false], [$owner->getResponse()->headers->hasCacheControlDirective('no-store'), $owner->getResponse()->headers->hasCacheControlDirective('private'),
            $owner->getResponse()->headers->hasCacheControlDirective('public')], 'Never stored, never shared.');
    }

    public function testAPressRecordsItselfAndLeadsOnlyWhereItsSectionLeads(): void
    {
        $page = $this->publishedCampaignPage();
        $browser = $this->browser();
        $browser->request('GET', '/c/spring-sale', server: ['HTTP_USER_AGENT' => self::BROWSER]);
        $html = $this->crawler($browser);
        $offers = $this->section($page, 'offers');
        $links = $html->filter('#offers .cl-landing-offer-actions a')->each(static fn(Crawler $a): string => (string) $a->attr('href'));
        self::assertSame(['/c/spring-sale/go/' . $offers->reference . '-1', '/c/spring-sale/go/' . $offers->reference . '-2'], $links, 'Each View goes through the page\'s own press address.');
        self::assertSame($links[0], $html->filter('#offers .cl-landing-offer-name a')->first()->attr('href'), 'The name is a link too, the same press.');

        $browser->request('GET', $links[1] . '?to=https://elsewhere.example.org/');
        self::assertSame([302, '/shop/mug-01'], [$browser->getResponse()->getStatusCode(), $browser->getResponse()->headers->get('Location')], 'Only to the offer the card shows.');
        $pressed = array_values(array_filter($this->events(), static fn(array $e): bool => $e['kind'] === Events::LANDING_PRESSED));
        self::assertSame([['product', 'mug-01', 'view', $offers->reference]], array_map(static fn(array $e): array => [$e['offer_type'], $e['offer_reference'],
            json_decode((string) $e['detail'], true)['action'] ?? null, json_decode((string) $e['detail'], true)['section'] ?? null], $pressed));

        $browser->request('GET', '/c/spring-sale/go/' . $offers->reference . '-3');
        self::assertSame(404, $browser->getResponse()->getStatusCode(), 'A press the section does not have.');
        $this->service(LandingPages::class, LandingPages::class)->unpublish($page->reference);
        $browser->request('GET', $links[0]);
        self::assertSame(404, $browser->getResponse()->getStatusCode(), 'Nothing to press on a page visitors cannot see.');
    }

    /** Spring sale, active, leading to /shop, with tea and the mug; fb1 with no destination, fb2 leading to /shop/tea; its page published. */
    private function publishedCampaignPage(): LandingPage
    {
        $campaigns = $this->service(Campaigns::class, Campaigns::class);
        $publications = $this->service(Publications::class, Publications::class);
        $campaigns->create(new CampaignInput('Spring sale', 'spring-sale', destination: '/shop', offers: ['product:tea-100', 'product:mug-01']));
        $campaigns->activate('spring-sale');
        $publications->create('spring-sale', new PublicationInput('facebook'));
        $publications->create('spring-sale', new PublicationInput('facebook', destination: '/shop/tea'));
        $pages = $this->service(LandingPages::class, LandingPages::class);
        $page = $pages->createForCampaign('spring-sale')->page;
        $this->answerTheQuestions($page);

        return $pages->publish($page->reference);
    }

    private function answerTheQuestions(LandingPage $page): void
    {
        $this->service(LandingSections::class, LandingSections::class)->save($page->reference, $this->section($page, 'faq')->reference,
            ['items' => [['question' => 'How is it brewed?', 'answer' => 'Four minutes in water just off the boil.']]]);
    }

    private function section(LandingPage $page, string $kind): LandingSection
    {
        foreach ($this->service(LandingSections::class, LandingSections::class)->list($page) as $section) {
            if ($section->kindKey === $kind) {
                return $section;
            }
        }

        throw new \LogicException('The page has no ' . $kind . ' section.');
    }

    /** @return list<Touch> the spring sale's openings, oldest first */
    private function touches(): array
    {
        $campaigns = $this->service(Campaigns::class, Campaigns::class);

        return array_reverse($this->service(Touches::class, Touches::class)->forCampaign($campaigns->require('spring-sale')->id));
    }

    /** @return list<array<string,mixed>> the journey's events, oldest first */
    private function events(): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM ' . Events::TABLE . ' ORDER BY id');
    }

    private function crawler(KernelBrowser $browser): Crawler
    {
        return new Crawler((string) $browser->getResponse()->getContent(), 'https://example.com/');
    }

    private function signedIn(string $email): KernelBrowser
    {
        $browser = $this->browser();
        $browser->setServerParameter('HTTP_USER_AGENT', self::BROWSER);
        $page = $browser->request('GET', '/sign-in');
        $browser->submit($page->filter('form')->form(['email' => $email]));
        $transport = $this->kernel->getContainer()->get(RecordingTransport::class);
        self::assertInstanceOf(RecordingTransport::class, $transport);
        self::assertSame(1, preg_match('#https://example\.com(/sign-in/link/[0-9a-f]{64})#', (string) $transport->last()->getTextBody(), $found));
        $page = $browser->request('GET', $found[1] ?? '');
        $browser->submit($page->filter('form')->form());

        return $browser;
    }

    private function browser(): KernelBrowser
    {
        $browser = new KernelBrowser($this->kernel);
        $browser->disableReboot();
        $browser->followRedirects(false);

        return $browser;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function service(string $id, string $class): object
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $service = $container->get($id);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
