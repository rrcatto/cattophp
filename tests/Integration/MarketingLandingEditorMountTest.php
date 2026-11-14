<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Marketing\Landing\LandingPage;
use CattoPHP\Marketing\Landing\LandingPages;
use CattoPHP\Marketing\Landing\LandingTemplates;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\RecordingTransport;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A product's landing page editor mounted at the test shop's own address (/shop/{reference}/landing, ExampleShopLanding)
 * through LandingEditor::routes() and an EditorPlace whose access the shop answers: its shopkeeper, who holds none of
 * the marketing permissions, makes the page, edits it and uploads to it, but neither publishes it nor sees the saved
 * templates; the Campaign Marketing Manager's own editor of the same page answers by LandingProject::access() instead.
 */
final class MarketingLandingEditorMountTest extends DatabaseTestCase
{
    private const BROWSER = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Firefox/144.0';

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

    public function testTheShopkeeperMakesAndEditsTheirProductsPageThereWithoutPublishingIt(): void
    {
        $this->templates()->save('Owner\'s spring special', 'Kept by the owner', [['kind' => 'hero', 'data' => []]]);
        $stranger = $this->browser();
        $stranger->request('GET', '/shop/tea-100/landing');
        self::assertSame(['/sign-in?return=/shop/tea-100/landing', 303], [$stranger->getResponse()->headers->get('Location'), $stranger->getResponse()->getStatusCode()]);

        $anna = $this->signedIn('anna@example.com');
        $empty = $anna->request('GET', '/shop/tea-100/landing');
        self::assertSame(200, self::statusOf($anna));
        self::assertSame(['The shop\'s landing page', 'Rooibos tea, 100 bags'], [trim($empty->filter('.cl-kicker')->text()), trim($empty->filter('h1')->text())]);
        self::assertStringContainsString('This product has no landing page', $empty->filter('#landing-make')->text(), 'The project\'s word for the offer.');
        self::assertSame(['several-offers'], $empty->filter('input[name="template"]')->each(static fn (Crawler $radio): string => (string) $radio->attr('value')),
            'The built-in templates that suit an offer\'s page alone: the saved ones are a marketing tool.');
        self::assertSame('/', $empty->filter('.cl-page-head-actions a')->last()->attr('href'), 'Its way back is the shop\'s.');
        $anna->request('GET', '/shop/no-such-thing/landing');
        self::assertSame(404, self::statusOf($anna));

        $anna->submit($empty->filter('form[action="/shop/tea-100/landing"]')->form());
        self::assertSame('/shop/tea-100/landing', $anna->getResponse()->headers->get('Location'));
        $editor = $anna->followRedirect();
        self::assertStringContainsString('The landing page is made as a draft at /landing/rooibos-tea-100-bags.', $editor->filter('.cl-flash-stack')->text());
        $page = $this->pages()->forOffer('product', 'tea-100');
        self::assertNotNull($page);
        self::assertCount(0, $editor->filter('#landing-publish form'), 'No Publish for someone who may not publish.');
        self::assertStringContainsString('Someone who may publish landing pages publishes this one.', $editor->filter('#landing-checks')->text());
        self::assertCount(0, $editor->filter('a[href="#templates"]'), 'No Save as a template.');
        self::assertSame(['several-offers'], $editor->filter('#templates input[name="template"]')->each(static fn (Crawler $radio): string => (string) $radio->attr('value')));
        self::assertSame('/shop/tea-100/landing/preview', $editor->filter('.cl-page-head-actions a')->first()->attr('href'), 'Every address of the editor is under the shop\'s.');

        $hero = $this->hero($page);
        $editor = $anna->request('GET', '/shop/tea-100/landing?section=' . $hero);
        self::assertSame('multipart/form-data', $editor->filter('#landing-edit-pane form')->attr('enctype'), 'The shop lets its keeper upload.');
        $anna->submit($editor->filter('#landing-edit-pane form.cl-landing-section-form')->form(['headline' => 'Rooibos for the spring']));
        self::assertSame('/shop/tea-100/landing?section=' . $hero . '#landing-edit', $anna->getResponse()->headers->get('Location'));
        self::assertStringContainsString('The hero section is saved.', $anna->followRedirect()->filter('.cl-flash-stack')->text());
        self::assertSame('Rooibos for the spring', $this->pages()->title($this->page($page)));

        $token = $this->token($editor);
        $anna->request('POST', '/shop/tea-100/landing/publish', ['_token' => $token]);
        self::assertSame(403, self::statusOf($anna), 'She may not publish it.');
        $anna->request('POST', '/shop/tea-100/landing/save-as-template', ['_token' => $token, 'name' => 'Mine']);
        self::assertSame(403, self::statusOf($anna), 'Nor save a template.');
        $anna->request('POST', '/shop/tea-100/landing/start-again', ['_token' => $token, 'template' => $this->templates()->saved()[0]->key ?? '']);
        self::assertStringContainsString('Choose one of the templates.', $anna->followRedirect()->filter('.cl-flash-stack')->text(), 'Nor start again from a saved one.');
        $anna->request('POST', '/shop/tea-100/landing', ['_token' => $token]);
        self::assertStringContainsString('This product already has a landing page.', $anna->followRedirect()->filter('.cl-flash-stack')->text());
        self::assertSame(LandingPage::DRAFT, $this->page($page)->status);

        $anna->request('GET', '/marketing/landing-pages/' . $page->reference);
        self::assertSame(403, self::statusOf($anna), 'Under the Campaign Marketing Manager the project\'s LandingProject answers, and it lets her see nothing.');

        $owner = $this->signedIn('owner@example.com');
        $editor = $owner->request('GET', '/marketing/landing-pages/' . $page->reference);
        self::assertSame(200, self::statusOf($owner), 'Whoever manages campaigns edits an offer\'s page there too.');
        self::assertCount(1, $editor->filter('#landing-publish form'));
        $owner->request('GET', '/shop/tea-100/landing');
        self::assertCount(1, $owner->getCrawler()->filter('#landing-publish form'), 'And at the shop\'s address.');
    }

    private function hero(LandingPage $page): string
    {
        foreach ($this->pages()->sections($page) as $section) {
            if ($section->isHero()) {
                return $section->reference;
            }
        }
        self::fail('The page has no hero.');
    }

    private function page(LandingPage $page): LandingPage
    {
        return $this->pages()->require($page->reference);
    }

    private function pages(): LandingPages
    {
        $pages = $this->container()->get(LandingPages::class);
        self::assertInstanceOf(LandingPages::class, $pages);

        return $pages;
    }

    private function templates(): LandingTemplates
    {
        $templates = $this->container()->get(LandingTemplates::class);
        self::assertInstanceOf(LandingTemplates::class, $templates);

        return $templates;
    }

    private function container(): ContainerInterface
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
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
        $browser->setServerParameter('HTTP_USER_AGENT', self::BROWSER);
        $browser->setServerParameter('REMOTE_ADDR', '203.0.113.9');
        $page = $browser->request('GET', '/sign-in');
        $browser->submit($page->filter('form')->form(['email' => $email]));
        $transport = $this->kernel->getContainer()->get(RecordingTransport::class);
        self::assertInstanceOf(RecordingTransport::class, $transport);
        self::assertSame(1, preg_match('#https://example\.com(/sign-in/link/[0-9a-f]{64})#', (string) $transport->last()->getTextBody(), $found));
        $page = $browser->request('GET', $found[1] ?? '');
        $browser->submit($page->filter('form')->form());
        self::assertSame('/', $browser->getResponse()->headers->get('Location'), 'Signed in.');

        return $browser;
    }

    private static function statusOf(KernelBrowser $browser): int
    {
        return $browser->getResponse()->getStatusCode();
    }

    private function token(Crawler $page): string
    {
        return (string) $page->filter('input[name="_token"]')->first()->attr('value');
    }
}
