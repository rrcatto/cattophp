<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Access\Roles;
use CattoPHP\Marketing\CampaignInput;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Landing\LandingPage;
use CattoPHP\Marketing\Landing\LandingPages;
use CattoPHP\Marketing\Landing\LandingSection;
use CattoPHP\Marketing\Landing\LandingSections;
use CattoPHP\Marketing\Landing\LandingTemplates;
use CattoPHP\Marketing\MarketingPermissions;
use CattoPHP\Marketing\Pages\LandingEditor;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\RecordingTransport;
use CattoPHP\Tests\Support\SamplePictures;
use CattoPHP\Theme\Menus;
use CattoPHP\Ui\Contract\AddressContract;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\RouterInterface;

/**
 * A landing page's editor under the Campaign Marketing Manager, in a project that imports its route file, the pages in
 * the database: the list, the New page and every action of the editor, for someone who may manage campaigns (changes),
 * someone who may only look (sees everything, changes nothing) and someone with neither (refused as the stock pages
 * refuse); a refused form back as typed with why, as a page (after a redirect) and as an htmx answer (422); htmx answers
 * are the part of the editor that changed, with what else changed as htmx partials, and plain posts redirect with a
 * notice; the campaign page's Landing page card and its two figures.
 */
final class MarketingLandingEditorTest extends DatabaseTestCase
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

    public function testEachPageAndActionHasItsAddressByReferenceAndTheMenuLinksTheList(): void
    {
        $router = $this->kernel->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $routes = $router->getRouteCollection();
        $expected = ['cattophp_marketing_landing_pages' => ['/marketing/landing-pages', 'GET'], 'cattophp_marketing_landing_make' => ['/marketing/landing-pages', 'POST'],
            'cattophp_marketing_landing_new' => ['/marketing/landing-pages/new', 'GET'],
            'cattophp_marketing_landing_template_delete' => ['/marketing/landing-pages/templates/{template}/delete', 'POST']];
        foreach (LandingEditor::ROUTES as $action => [$method, $ending]) {
            if ($action !== 'make') {
                $expected['cattophp_marketing_landing_' . $action] = ['/marketing/landing-pages/{reference}' . $ending, $method];
            }
        }
        foreach ($expected as $name => [$path, $method]) {
            $route = $routes->get($name);
            self::assertNotNull($route, $name);
            self::assertSame([$path, [$method]], [$route->getPath(), $route->getMethods()], $name);
        }
        self::assertNull($routes->get('cattophp_marketing_landing_make_page'), 'CattoPHP\'s own editor makes no page: New does.');
        self::assertSame('/marketing/landing-pages/{reference}', $routes->get('cattophp_marketing_landing_edit')?->getPath(), 'The name the activity log links a page by.');
        self::assertSame(['/shop/{reference}/landing', ['POST']], [$routes->get('example_shop_landing_make')?->getPath(), $routes->get('example_shop_landing_make')?->getMethods()],
            'A project mounting it for an offer that may have no page gets the post that makes one.');
        self::assertSame([], AddressContract::rowNumbers($routes), 'A page and a section by their references, a version as v and its number.');
        $router->getContext()->setMethod('GET');
        self::assertSame('cattophp_marketing_landing_new', $router->match('/marketing/landing-pages/new')['_route'], 'New is no page\'s reference.');
        self::assertSame(['cattophp_marketing_landing_version', 'v3'], [$router->match('/marketing/landing-pages/abcd1234/versions/v3')['_route'],
            $router->match('/marketing/landing-pages/abcd1234/versions/v3')['version']]);

        $this->signedIn('owner@example.com')->request('GET', '/marketing/landing-pages');
        $menus = $this->container()->get(Menus::class);
        self::assertInstanceOf(Menus::class, $menus);
        self::assertContains(['Landing pages', '/marketing/landing-pages'], array_map(static fn (array $entry): array => [$entry['label'], $entry['href']], $menus->sections()[0]['entries'] ?? []),
            'An entry of the Marketing drop-down, never a row of links of its own.');
    }

    public function testAStrangerIsSentToSignInAndSomeoneWithoutThePermissionIsRefusedEverything(): void
    {
        $page = $this->campaignPage();
        $faq = $this->section($page, 'faq');
        $base = '/marketing/landing-pages/' . $page->reference;
        $stranger = $this->browser();
        $stranger->request('GET', $base);
        self::assertSame(['/sign-in?return=' . $base, 303], [$stranger->getResponse()->headers->get('Location'), self::statusOf($stranger)]);

        $anna = $this->signedIn('anna@example.com');
        foreach (['/marketing/landing-pages', '/marketing/landing-pages/new', $base, $base . '?section=' . $faq->reference, $base . '/preview', $base . '/versions/v1'] as $address) {
            $anna->request('GET', $address);
            self::assertSame(403, self::statusOf($anna), $address . ': Anna holds no role that allows it.');
        }
        $token = $this->token($this->signedIn('owner@example.com')->request('GET', $base));
        foreach (['/marketing/landing-pages', $base . '/settings', $base . '/publish', $base . '/unpublish', $base . '/delete', $base . '/save-as-template', $base . '/start-again',
            $base . '/sections', $base . '/sections/' . $faq->reference, $base . '/sections/' . $faq->reference . '/hide', $base . '/sections/' . $faq->reference . '/width',
            $base . '/sections/' . $faq->reference . '/shown-when', $base . '/sections/' . $faq->reference . '/move', $base . '/sections/' . $faq->reference . '/remove',
            $base . '/versions/v1/use', '/marketing/landing-pages/templates/abcd1234/delete'] as $action) {
            $anna->request('POST', $action, ['_token' => $token, 'kind' => 'words', 'width' => 'half', 'index' => '0', 'name' => 'Taken over', 'for' => 'campaign', 'campaign' => 'spring-sale']);
            self::assertSame(403, self::statusOf($anna), $action);
        }
        $after = $this->landingPages()->require($page->reference);
        self::assertSame([LandingPage::DRAFT, 4], [$after->status, count($this->landingPages()->sections($after))], 'Nothing changed.');
        self::assertSame('full', $this->section($after, 'faq')->width);
    }

    public function testSomeoneWhoMayOnlyLookSeesTheListTheEditorAndThePreviewWithoutAForm(): void
    {
        $page = $this->campaignPage();
        $faq = $this->section($page, 'faq');
        $this->landingTemplates()->save('Spring special', 'For the spring', []);
        $this->role('WATCHER', [MarketingPermissions::VIEW], '2');
        $anna = $this->signedIn('anna@example.com');
        $list = $anna->request('GET', '/marketing/landing-pages');
        self::assertSame(200, self::statusOf($anna));
        self::assertSame('Spring sale', trim($list->filter('#landing-results tbody td a')->first()->text()), 'The page, linking to its editor.');
        self::assertCount(0, $list->filter('#templates'), 'The saved templates are a tool for those who manage campaigns.');
        self::assertCount(0, $list->filter('a[href="/marketing/landing-pages/new"]'));

        $editor = $anna->request('GET', '/marketing/landing-pages/' . $page->reference . '?section=' . $faq->reference);
        self::assertSame(200, self::statusOf($anna));
        self::assertCount(0, $editor->filter('form[method="post"]'), 'No forms for someone who may only look.');
        self::assertSame('Questions and answers', trim($editor->filter('#landing-edit-heading')->text()), 'Its heading has no Edit: before it.');
        self::assertCount(1, $editor->filter('#landing-edit-pane fieldset.cl-landing-fields[disabled]'), 'The section\'s fields, greyed.');
        self::assertCount(0, $editor->filter('[data-cl-sortable]'), 'Nothing to drag.');
        self::assertStringNotContainsString(' style=', (string) $anna->getResponse()->getContent(), 'No inline style.');
        self::assertCount(0, $editor->filter('#templates'), 'No templates to start again from.');

        $anna->request('GET', '/marketing/landing-pages/new');
        self::assertSame(403, self::statusOf($anna));
        $token = $this->token($this->signedIn('owner@example.com')->request('GET', '/marketing/landing-pages/' . $page->reference));
        $anna->request('POST', '/marketing/landing-pages/' . $page->reference . '/sections/' . $faq->reference . '/hide', ['_token' => $token]);
        self::assertSame(403, self::statusOf($anna), 'Changing needs MARKETING.CAMPAIGN.MANAGE.');
    }

    public function testANewPageIsRefusedAsTypedThenMadeAndListed(): void
    {
        $this->springSale();
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/marketing/landing-pages/new');
        self::assertSame(['New landing page', 2, 2], [trim($page->filter('h1')->text()), $page->filter('input[name="for"]')->count(), $page->filter('input[name="template"]')->count()],
            'For a campaign or an offer, and the two built-in templates, as feature cards.');
        $owner->submit($page->filter('form[action="/marketing/landing-pages"]')->form(['for' => 'campaign', 'campaign' => '', 'template' => 'special-with-end-date']));
        self::assertSame('/marketing/landing-pages/new', $owner->getResponse()->headers->get('Location'));
        $page = $owner->followRedirect();
        self::assertStringContainsString('The landing page was not made: see why beside each field.', $page->filter('.cl-flash-stack')->text());
        self::assertStringContainsString('Choose one of the campaigns.', $page->filter('#landing-new-campaign-error')->text());
        self::assertSame('special-with-end-date', $page->filter('input[name="template"]:checked')->attr('value'), 'As chosen.');

        $owner->submit($page->filter('form[action="/marketing/landing-pages"]')->form(['for' => 'campaign', 'campaign' => 'spring-sale', 'template' => 'special-with-end-date']));
        $made = $this->landingPages()->forCampaign($this->campaigns()->require('spring-sale'));
        self::assertNotNull($made);
        self::assertSame('/marketing/landing-pages/' . $made->reference, $owner->getResponse()->headers->get('Location'), 'On to its editor.');
        $editor = $owner->followRedirect();
        self::assertStringContainsString('The landing page is made as a draft at /c/spring-sale.', $editor->filter('.cl-flash-stack')->text());
        self::assertSame(['Draft', 'For: Spring sale', '/c/spring-sale', 'The campaign is running'], $editor->filter('.cl-page-head-badges .cl-ui-badge')->each(static fn (Crawler $badge): string => trim($badge->text())));
        self::assertSame(['Countdown', 'Products and bundles', 'Questions and answers', 'Call to action'], $editor->filter('#landing-section-list li .cl-tree-name')->each(static fn (Crawler $name): string => trim($name->text())));

        $list = $owner->request('GET', '/marketing/landing-pages?show=campaign');
        self::assertSame(['Spring sale', 'Campaign Spring sale', '/c/spring-sale', 'Draft', '5', '0'], array_values(array_filter($list->filter('#landing-results tbody tr')->first()->filter('td')
            ->each(static fn (Crawler $cell): string => trim(preg_replace('/\s+/', ' ', $cell->text()) ?? '')), static fn (string $text, int $index): bool => $index !== 5, ARRAY_FILTER_USE_BOTH)));
        self::assertStringContainsString('Pages of products and bundles', $list->filter('#landing-pages-show')->text(), 'The project\'s word for its offers.');
        $empty = $owner->request('GET', '/marketing/landing-pages?show=offer');
        self::assertStringContainsString('None of these', $empty->filter('#landing-results')->text());
    }

    public function testASectionIsAddedRefusedAsTypedAndSavedWithoutJavaScript(): void
    {
        $page = $this->campaignPage();
        $owner = $this->signedIn('owner@example.com');
        $base = '/marketing/landing-pages/' . $page->reference;
        $editor = $owner->request('GET', $base);
        self::assertSame(['Hero (already on the page)', 'Questions and answers (on the page, you can add another)', 'Countdown'],
            [$this->card($editor, 'hero'), $this->card($editor, 'faq'), $this->card($editor, 'countdown')], 'A kind a page holds once is disabled once it is there.');
        self::assertNotNull($editor->filter('#landing-add input[value="hero"]')->attr('disabled'), 'The hero is there already.');
        self::assertNull($editor->filter('#landing-add input[value="countdown"]')->attr('disabled'));

        $owner->submit($editor->filter('#landing-add form')->form(['kind' => 'benefits']));
        $benefits = $this->section($page, 'benefits');
        self::assertSame($base . '?section=' . $benefits->reference . '#landing-edit', $owner->getResponse()->headers->get('Location'), 'Its form opens.');
        $editor = $owner->followRedirect();
        self::assertStringContainsString('A benefits section is added at the bottom, hidden until you save it.', $editor->filter('.cl-flash-stack')->text());
        self::assertSame('Edit: Benefits', trim($editor->filter('#landing-edit-heading')->text()));
        self::assertStringContainsString('This section is new: it is hidden until you save it.', $editor->filter('#landing-edit-pane')->text());
        $row = $editor->filter('li[data-cl-sortable-item="' . $benefits->reference . '"]');
        self::assertSame(['Hidden', 'Not saved yet'], $row->filter('.cl-ui-badge')->each(static fn (Crawler $badge): string => trim($badge->text())));
        self::assertSame(['Benefit 1', 'Explanation 1', 'Benefit 2', 'Explanation 2'], $editor->filter('#landing-edit-pane .cl-landing-row label')->each(static fn (Crawler $label): string => trim($label->text())),
            'Two spare rows.');

        $form = $editor->filter('#landing-edit-pane form.cl-landing-section-form')->form(['heading' => 'Why it is worth it', 'items[0][title]' => 'Grown in the Cederberg', 'items[0][text]' => '']);
        $owner->submit($form);
        self::assertSame($base . '?section=' . $benefits->reference . '#landing-edit', $owner->getResponse()->headers->get('Location'));
        $editor = $owner->followRedirect();
        self::assertStringContainsString('The section was not saved: see why beside each field.', $editor->filter('.cl-flash-stack')->text());
        self::assertSame(['Why it is worth it', 'Grown in the Cederberg'], [$editor->filter('#landing-heading')->attr('value'), $editor->filter('#landing-item-0-title')->attr('value')], 'As typed.');
        self::assertStringContainsString('needs its explanation as well', $editor->filter('#landing-item-0-text-error')->text());
        self::assertFalse($this->section($page, 'benefits')->enabled, 'Nothing saved.');

        $owner->submit($editor->filter('#landing-edit-pane form.cl-landing-section-form')->form(['items[0][text]' => 'Picked by hand on the slopes, dried in the sun.']));
        $editor = $owner->followRedirect();
        self::assertStringContainsString('The benefits section is saved.', $editor->filter('.cl-flash-stack')->text());
        $saved = $this->section($page, 'benefits');
        self::assertSame([true, false, 'Why it is worth it', [['title' => 'Grown in the Cederberg', 'text' => 'Picked by hand on the slopes, dried in the sun.']]],
            [$saved->enabled, $saved->unsaved, $saved->data['heading'], $saved->data['items']], 'Shown at its first save.');
        self::assertSame('"Why it is worth it" · 1 benefit', trim($editor->filter('li[data-cl-sortable-item="' . $saved->reference . '"] .cl-tree-meta')->text()));
        self::assertStringContainsString('Benefits', $editor->filter('.cl-page-sketch')->text(), 'The drawing of the page shows it now.');
    }

    public function testHtmxAnswersArePartsOfTheEditorAndARefusalComesBackAsTypedWith422(): void
    {
        $page = $this->campaignPage();
        $faq = $this->section($page, 'faq');
        $offers = $this->section($page, 'offers');
        $owner = $this->signedIn('owner@example.com');
        $base = '/marketing/landing-pages/' . $page->reference;
        $token = $this->token($owner->request('GET', $base));

        $owner->request('GET', $base . '?section=' . $faq->reference, [], [], ['HTTP_HX_REQUEST' => 'true']);
        $pane = (string) $owner->getResponse()->getContent();
        self::assertSame(200, self::statusOf($owner));
        self::assertStringStartsWith('<div class="cl-landing-column" id="landing-edit-pane">', trim($pane), 'Edit brings the open section alone.');
        self::assertStringNotContainsString('<html', $pane);

        $owner->request('POST', $base . '/sections/' . $faq->reference, ['_token' => $token, 'heading' => 'Asked often', 'items' => [['question' => 'Is it caffeine free?', 'answer' => '']]], [], ['HTTP_HX_REQUEST' => 'true']);
        self::assertSame(422, self::statusOf($owner), 'Refused, as typed.');
        $answer = new Crawler((string) $owner->getResponse()->getContent());
        self::assertSame(['Asked often', 'Is it caffeine free?'], [$answer->filter('#landing-heading')->attr('value'), $answer->filter('#landing-item-0-question')->attr('value')]);
        self::assertStringContainsString('needs its answer as well', $answer->filter('#landing-item-0-answer-error')->text());
        self::assertStringContainsString('The section was not saved: see why beside each field.', $answer->filter('#landing-edit-pane .cl-ui-notice')->text());

        $owner->request('POST', $base . '/sections/' . $faq->reference, ['_token' => $token, 'heading' => 'Asked often', 'items' => [['question' => 'Is it caffeine free?', 'answer' => 'Yes, rooibos has none.']]],
            [], ['HTTP_HX_REQUEST' => 'true']);
        self::assertSame(200, self::statusOf($owner));
        $content = (string) $owner->getResponse()->getContent();
        $answer = new Crawler($content);
        self::assertStringContainsString('The questions and answers section is saved.', $answer->filter('#landing-edit-pane .cl-ui-notice')->text());
        foreach (['#landing-section-list', '#landing-checks', '#landing-publish', '#landing-add'] as $part) {
            self::assertStringContainsString('<hx-partial hx-target="' . $part . '" hx-swap="outerHTML">', $content, 'The ' . $part . ' it changed comes too.');
        }
        self::assertCount(0, $answer->filter('#landing-checks .cl-ui-notice'), 'Nothing stops it being published now.');
        self::assertNull($answer->filter('#landing-publish button')->attr('disabled'), 'Publish is pressable.');
        self::assertStringContainsString('Yes, rooibos has none.', $answer->filter('#landing-section-preview')->text(), 'Its preview, drawn as the page draws it.');

        $owner->request('POST', $base . '/sections/' . $offers->reference . '/width', ['_token' => $token, 'width' => 'half'], [], ['HTTP_HX_REQUEST' => 'true']);
        self::assertSame(200, self::statusOf($owner));
        $list = new Crawler((string) $owner->getResponse()->getContent());
        self::assertSame('half', $list->filter('#landing-section-list li[data-cl-sortable-item="' . $offers->reference . '"] input[name="width"]:checked')->attr('value'), 'The list, as saved.');
        self::assertSame('half', $this->section($page, 'offers')->width);

        $order = implode(',', [$offers->reference, $faq->reference, $this->section($page, 'cta')->reference]);
        $owner->request('POST', $base . '/sections/' . $faq->reference . '/move', ['_token' => $token, 'index' => '2', 'order' => strrev($order)], [], ['HTTP_HX_REQUEST' => 'true']);
        self::assertSame(409, self::statusOf($owner), 'A move from an order that is out of date is refused, not guessed at.');
        $list = new Crawler((string) $owner->getResponse()->getContent());
        self::assertSame('The sections were changed elsewhere since this page was loaded. The saved order is shown.', $list->filter('#landing-section-list')->attr('data-error'));
        $owner->request('POST', $base . '/sections/' . $faq->reference . '/move', ['_token' => $token, 'index' => '2', 'order' => $order], [], ['HTTP_HX_REQUEST' => 'true']);
        self::assertSame(200, self::statusOf($owner));
        self::assertSame(['offers', 'cta', 'faq'], array_map(static fn (LandingSection $section): string => $section->kindKey,
            array_values(array_filter($this->landingPages()->sections($page), static fn (LandingSection $section): bool => !$section->isHero()))));

        $owner->request('POST', $base . '/sections/' . $faq->reference . '/move', ['_token' => $token, 'index' => '0', 'order' => implode(',', [$offers->reference, $this->section($page, 'cta')->reference, $faq->reference])]);
        self::assertSame([303, $base . '#sections'], [self::statusOf($owner), $owner->getResponse()->headers->get('Location')], 'Without JavaScript a move redirects to the list.');
        self::assertStringContainsString('Questions and answers is moved.', $owner->followRedirect()->filter('.cl-flash-stack')->text());

        $owner->request('POST', $base . '/sections/' . $faq->reference . '/hide', ['_token' => $token, 'open' => $faq->reference], [], ['HTTP_HX_REQUEST' => 'true']);
        $content = (string) $owner->getResponse()->getContent();
        self::assertStringStartsWith('<div class="cl-tree cl-tree--landing" id="landing-section-list"', trim($content));
        self::assertStringContainsString('<hx-partial hx-target="#landing-edit-pane" hx-swap="outerHTML">', $content, 'The open section says it is hidden now.');
        self::assertStringContainsString('This section is hidden', $content);

        $owner->request('POST', $base . '/sections/' . $faq->reference . '/shown-when', ['_token' => $token, 'shown_when' => 'running'], [], ['HTTP_HX_REQUEST' => 'true']);
        self::assertSame('running', $this->section($page, 'faq')->shownWhen);
        self::assertStringContainsString('While the campaign runs', (string) $owner->getResponse()->getContent());

        $owner->request('POST', $base . '/settings', ['_token' => 'stale', 'meta_title' => 'Spring at the tea shop'], [], ['HTTP_HX_REQUEST' => 'true']);
        self::assertSame(403, self::statusOf($owner), 'A form that had run out does nothing.');
        self::assertStringContainsString('That form had run out', (string) $owner->getResponse()->getContent());
        self::assertNull($this->landingPages()->require($page->reference)->metaTitle);
    }

    public function testThePagesSettingsAreSavedInPlaceAndRefusedAsTyped(): void
    {
        $page = $this->landingPages()->createForOffer('product', 'tea-100', 'several-offers')->page;
        $owner = $this->signedIn('owner@example.com');
        $base = '/marketing/landing-pages/' . $page->reference;
        $editor = $owner->request('GET', $base);
        self::assertSame('rooibos-tea-100-bags', $editor->filter('#landing-slug')->attr('value'), 'An offer\'s page has its own address.');
        self::assertCount(0, $editor->filter('input[name="not_running"]'), 'Only a campaign\'s page is ever not running.');
        $token = $this->token($editor);

        $owner->request('POST', $base . '/settings', ['_token' => $token, 'slug' => 'Not An Address!', 'meta_title' => 'Rooibos for spring'], [], ['HTTP_HX_REQUEST' => 'true']);
        self::assertSame(422, self::statusOf($owner));
        $answer = new Crawler((string) $owner->getResponse()->getContent());
        self::assertSame(['Not An Address!', 'Rooibos for spring'], [$answer->filter('#landing-slug')->attr('value'), $answer->filter('#landing-meta-title')->attr('value')], 'As typed.');
        self::assertStringContainsString('An address is small letters and digits joined by single hyphens', $answer->filter('#landing-slug-error')->text());

        $owner->request('POST', $base . '/settings', ['_token' => $token, 'slug' => 'spring-rooibos', 'meta_title' => 'Rooibos for spring', 'meta_description' => '', 'share_picture' => ['source' => 'none']],
            [], ['HTTP_HX_REQUEST' => 'true']);
        self::assertSame(200, self::statusOf($owner));
        $content = (string) $owner->getResponse()->getContent();
        self::assertStringContainsString('The page\'s settings are saved.', (new Crawler($content))->filter('#settings .cl-ui-notice')->text());
        self::assertStringContainsString('<hx-partial hx-target="#landing-address" hx-swap="outerHTML">', $content);
        self::assertStringContainsString('/landing/spring-rooibos', $content, 'The head\'s address follows.');
        self::assertSame(['spring-rooibos', 'Rooibos for spring'], [$this->landingPages()->require($page->reference)->slug, $this->landingPages()->require($page->reference)->metaTitle]);

        $campaign = $this->campaignPage();
        $editor = $owner->request('GET', '/marketing/landing-pages/' . $campaign->reference);
        self::assertSame(3, $editor->filter('input[name="not_running"]')->count(), 'Keep, note or forward, as feature cards.');
        $owner->submit($editor->filter('form[action="/marketing/landing-pages/' . $campaign->reference . '/settings"]')->form(['not_running' => 'forward', 'not_running_to' => 'elsewhere']));
        self::assertSame('/marketing/landing-pages/' . $campaign->reference . '#settings', $owner->getResponse()->headers->get('Location'));
        $editor = $owner->followRedirect();
        self::assertSame('elsewhere', $editor->filter('#landing-not-running-to')->attr('value'), 'As typed, after the redirect.');
        self::assertStringContainsString('Type a path on the site starting with /', $editor->filter('#landing-not-running-to-error')->text());
        $owner->submit($editor->filter('form[action="/marketing/landing-pages/' . $campaign->reference . '/settings"]')->form(['not_running' => 'forward', 'not_running_to' => '/offers']));
        self::assertStringContainsString('The page\'s settings are saved.', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        self::assertSame(['forward', '/offers'], [$this->landingPages()->require($campaign->reference)->notRunning, $this->landingPages()->require($campaign->reference)->notRunningTo]);
    }

    public function testAPageIsPublishedKeptInVersionsUsedAgainSavedAsATemplateAndNeverDeletedOncePublic(): void
    {
        $page = $this->campaignPage();
        $faq = $this->section($page, 'faq');
        $owner = $this->signedIn('owner@example.com');
        $base = '/marketing/landing-pages/' . $page->reference;
        $editor = $owner->request('GET', $base);
        self::assertNotNull($editor->filter('#landing-publish button')->attr('disabled'), 'Publish waits for what is listed.');
        self::assertStringContainsString('Questions and answers has no questions yet.', $editor->filter('#landing-checks')->text());
        $token = $this->token($editor);
        $owner->request('POST', $base . '/publish', ['_token' => $token]);
        self::assertStringContainsString('The page was not published. Questions and answers has no questions yet.', $owner->followRedirect()->filter('.cl-flash-stack')->text());

        $this->sections()->save($page->reference, $faq->reference, ['items' => [['question' => 'Is it caffeine free?', 'answer' => 'Yes.']]]);
        $owner->request('POST', $base . '/publish', ['_token' => $token]);
        $editor = $owner->followRedirect();
        self::assertStringContainsString('The page is published: anyone can open it at /c/spring-sale.', $editor->filter('.cl-flash-stack')->text());
        self::assertSame(['Published', 'View'], [trim($editor->filter('.cl-page-head-badges .cl-ui-badge')->first()->text()), trim($editor->filter('.cl-page-head-actions a')->first()->text())]);
        self::assertCount(0, $editor->filter('form[action="' . $base . '/delete"]'), 'A page that has been public is never deleted.');
        self::assertSame(['1', 'First published', 'Published now'], $this->versionRow($editor, 0));

        $this->sections()->save($page->reference, $faq->reference, ['heading' => 'Asked often', 'items' => [['question' => 'Is it caffeine free?', 'answer' => 'Yes, it has none.']]]);
        $editor = $owner->request('GET', $base);
        self::assertSame(['2', 'The Questions and answers section changed', 'Published now'], $this->versionRow($editor, 0));
        self::assertSame('1', $this->versionRow($editor, 1)[0]);
        self::assertStringStartsWith('Replaced ', $this->versionRow($editor, 1)[2]);

        $owner->request('GET', $base . '/versions/v1');
        self::assertSame([200, 'noindex, nofollow'], [self::statusOf($owner), $owner->getResponse()->headers->get('X-Robots-Tag')], 'A kept version is drawn as a preview, never indexed.');
        self::assertStringContainsString('What visitors saw from', (string) $owner->getResponse()->getContent());
        $owner->request('GET', $base . '/versions/v9');
        self::assertSame(404, self::statusOf($owner));
        $owner->request('GET', $base . '/preview');
        self::assertSame([200, 'noindex, nofollow'], [self::statusOf($owner), $owner->getResponse()->headers->get('X-Robots-Tag')]);

        $owner->submit($editor->filter('form[action="' . $base . '/versions/v1/use"]')->form());
        self::assertSame($base . '#versions', $owner->getResponse()->headers->get('Location'));
        $editor = $owner->followRedirect();
        self::assertStringContainsString('Version 1 is on the page again.', $editor->filter('.cl-flash-stack')->text());
        self::assertSame(['3', 'Version 1 used again', 'Published now'], $this->versionRow($editor, 0));
        self::assertSame('', (string) ($this->section($page, 'faq')->data['heading'] ?? ''), 'Its content is back.');

        $owner->submit($editor->filter('form[action="' . $base . '/save-as-template"]')->form(['name' => '', 'note' => 'For the spring']));
        $editor = $owner->followRedirect();
        self::assertStringContainsString('Type a name for the template', $editor->filter('#template-name-error')->text());
        self::assertSame('For the spring', $editor->filter('#template-note')->attr('value'), 'As typed.');
        $owner->submit($editor->filter('form[action="' . $base . '/save-as-template"]')->form(['name' => 'Spring special', 'note' => 'For the spring']));
        self::assertStringContainsString('The page is saved as the template Spring special.', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        $saved = $this->landingTemplates()->saved()[0] ?? null;
        self::assertNotNull($saved);

        $editor = $owner->request('GET', $base);
        self::assertCount(1, $editor->filter('#templates input[value="' . $saved->key . '"]'), 'The saved template is offered to start again from.');
        $owner->submit($editor->filter('form[action="' . $base . '/start-again"]')->form(['template' => 'several-offers']));
        self::assertStringContainsString('The page is started again from the template Several offers. What it showed before is kept in its earlier versions.', $owner->followRedirect()->filter('.cl-flash-stack')->text());

        $list = $owner->request('GET', '/marketing/landing-pages');
        $owner->submit($list->filter('form[action="/marketing/landing-pages/templates/' . $saved->reference . '/delete"]')->form());
        self::assertStringContainsString('The template Spring special is deleted.', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        self::assertSame([], $this->landingTemplates()->saved());

        $owner->request('POST', $base . '/unpublish', ['_token' => $token]);
        $editor = $owner->followRedirect();
        self::assertStringContainsString('The page is unpublished.', $editor->filter('.cl-flash-stack')->text());
        self::assertStringContainsString('This page has been public, so it is unpublished rather than deleted.', $editor->filter('#landing-checks')->text());
        $owner->request('POST', $base . '/delete', ['_token' => $token]);
        self::assertStringContainsString('This page has been public, so it is unpublished rather than deleted.', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        self::assertNotNull($this->landingPages()->get($page->reference));

        $draft = $this->landingPages()->createForOffer('product', 'mug-01')->page;
        $owner->request('POST', '/marketing/landing-pages/' . $draft->reference . '/delete', ['_token' => $token]);
        self::assertSame('/marketing/landing-pages', $owner->getResponse()->headers->get('Location'), 'Back to the list, the page gone.');
        self::assertStringContainsString('The landing page is deleted', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        self::assertNull($this->landingPages()->get($draft->reference));
    }

    public function testEveryKindsFormOpensBesideTheListAndSavesWhatItShows(): void
    {
        $page = $this->campaignPage();
        $owner = $this->signedIn('owner@example.com');
        $base = '/marketing/landing-pages/' . $page->reference;
        $fields = ['words' => 'text', 'picture' => 'picture[source]', 'video' => 'address', 'audience' => 'items[0][title]', 'outcomes' => 'items', 'benefits' => 'items[0][text]',
            'countdown' => 'words', 'offers' => 'offers[]', 'faq' => 'items[0][answer]', 'cta' => 'target_address', 'hero' => 'secondary'];
        foreach ($fields as $kind => $field) {
            $section = $kind === 'hero' ? $this->section($page, 'hero') : $this->sections()->add($page->reference, $kind);
            $editor = $owner->request('GET', $base . '?section=' . $section->reference);
            self::assertSame(200, self::statusOf($owner), $kind);
            self::assertGreaterThan(0, $editor->filter('#landing-edit-pane form [name="' . $field . '"]')->count(), $kind . '\'s form has ' . $field . '.');
            self::assertCount($kind === 'hero' ? 0 : 3, $editor->filter('#landing-edit-pane input[name="shown_when"]'), $kind . ': Always, while it runs, while it is not running; the hero always.');
        }
        $countdown = $owner->request('GET', $base . '?section=' . $this->section($page, 'countdown')->reference);
        self::assertStringContainsString('The campaign has no end date', $countdown->filter('#landing-edit-pane')->text(), 'A campaign\'s page counts to the campaign\'s end.');
        $offers = $owner->request('GET', $base . '?section=' . $this->section($page, 'offers')->reference);
        self::assertSame(['product:tea-100', 'product:mug-01', '', '', ''], $offers->filter('#landing-edit-pane select[name="offers[]"]')->each(static fn (Crawler $select): string => $select->filter('option[selected]')->count() > 0 ? (string) $select->filter('option[selected]')->attr('value') : ''),
            'The campaign\'s offers in order, and three empty rows.');
        self::assertSame(['view', 'favourite', 'buy'], $offers->filter('#landing-edit-pane input[name="buttons[]"]:checked')->each(static fn (Crawler $tick): string => (string) $tick->attr('value')));
        self::assertCount(1, $offers->filter('#landing-edit-pane input[name="show_promotion"]'), 'The campaign has a promotion.');
        $hero = $owner->request('GET', $base . '?section=' . $this->section($page, 'hero')->reference);
        self::assertSame(['none', 'offers', 'faq', 'cta'], array_slice($hero->filter('#landing-secondary option')->each(static fn (Crawler $option): string => (string) $option->attr('value')), 0, 4),
            'The second button leads to a part of the page.');
        self::assertCount(1, $hero->filter('#landing-not-running-headline'), 'A campaign\'s hero has words for while it is not running.');
        self::assertNull($hero->filter('#landing-text')->attr('placeholder'), 'A campaign\'s hero has no offer\'s words to fall back on, so its words have no placeholder.');

        $cta = $this->section($page, 'cta');
        $token = $this->token($hero);
        $owner->request('POST', $base . '/sections/' . $cta->reference, ['_token' => $token, 'heading' => 'Ready for spring?', 'button' => 'address', 'target_section' => 'faq', 'target_address' => '/shop',
            'label' => 'See the shop']);
        self::assertSame(['address', '/shop', 'See the shop'], [$this->section($page, 'cta')->data['button'], $this->section($page, 'cta')->data['target'], $this->section($page, 'cta')->data['label']],
            'The button\'s choice says which of the two targets counts.');
        $owner->request('POST', $base . '/sections/' . $cta->reference, ['_token' => $token, 'button' => 'section', 'target_section' => 'faq', 'target_address' => '/shop']);
        self::assertSame(['section', 'faq'], [$this->section($page, 'cta')->data['button'], $this->section($page, 'cta')->data['target']]);
        $outcomes = $this->section($page, 'outcomes');
        $owner->request('POST', $base . '/sections/' . $outcomes->reference, ['_token' => $token, 'items' => "Brew a perfect pot\n\nPair it with lemon"]);
        self::assertSame(['Brew a perfect pot', 'Pair it with lemon'], $this->section($page, 'outcomes')->data['items'], 'One outcome a line, empty lines dropped.');
        $form = $owner->request('GET', $base . '?section=' . $outcomes->reference);
        self::assertSame("Brew a perfect pot\nPair it with lemon", $form->filter('#landing-items')->text(null, false), 'Back as it is kept.');
    }

    public function testAHeroPictureIsUploadedAndChosenAndAnUnusablePictureRefused(): void
    {
        $page = $this->campaignPage();
        $hero = $this->section($page, 'hero');
        $owner = $this->signedIn('owner@example.com');
        $base = '/marketing/landing-pages/' . $page->reference;
        $editor = $owner->request('GET', $base . '?section=' . $hero->reference);
        $form = $editor->filter('#landing-edit-pane form.cl-landing-section-form');
        self::assertSame('multipart/form-data', $form->attr('enctype'), 'It takes an upload.');
        self::assertSame(['upload', 'offer', 'none'], $form->filter('input[name="picture[source]"]')->each(static fn (Crawler $radio): string => (string) $radio->attr('value')),
            'Where the picture comes from, as feature cards.');
        self::assertSame('offer', $form->filter('input[name="picture[source]"]:checked')->attr('value'), 'The offer\'s own to start.');

        $token = $this->token($editor);
        $owner->request('POST', $base . '/sections/' . $hero->reference, ['_token' => $token, 'headline' => 'Spring at the tea shop', 'picture' => ['source' => 'upload', 'refs' => ['upload' => '']],
            'upload_title' => ['picture' => 'Spring']], ['upload' => ['picture' => new UploadedFile(SamplePictures::file($this->folder, SamplePictures::PNG), 'spring.png', 'image/png', null, true)]]);
        self::assertSame(303, self::statusOf($owner));
        $saved = $this->section($page, 'hero');
        $picture = $saved->data['picture'] ?? null;
        self::assertIsArray($picture);
        self::assertSame(['upload', 'Spring at the tea shop'], [$picture['source'] ?? '', $saved->data['headline']]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\.png$/', (string) ($picture['ref'] ?? ''), 'Kept by its content.');

        $owner->request('POST', $base . '/sections/' . $hero->reference, ['_token' => $token, 'picture' => ['source' => 'upload', 'refs' => ['upload' => str_repeat('a', 64) . '.png']]],
            [], ['HTTP_HX_REQUEST' => 'true']);
        self::assertSame(422, self::statusOf($owner));
        self::assertStringContainsString('That picture is no longer there: choose another.', (string) $owner->getResponse()->getContent());

        $owner->request('POST', $base . '/sections/' . $hero->reference, ['_token' => $token, 'picture' => ['source' => 'upload']], ['upload' => ['picture' => new UploadedFile(
            SamplePictures::file($this->folder, SamplePictures::PNG), 'notes.txt', 'text/plain', UPLOAD_ERR_INI_SIZE, true)]], ['HTTP_HX_REQUEST' => 'true']);
        self::assertSame(422, self::statusOf($owner));
        self::assertStringContainsString('The picture could not be uploaded', (string) $owner->getResponse()->getContent());
    }

    public function testTheCampaignsPageHasItsLandingPageCardWithItsTwoFigures(): void
    {
        $this->springSale();
        $owner = $this->signedIn('owner@example.com');
        $campaign = $owner->request('GET', '/marketing/campaigns/spring-sale');
        self::assertStringContainsString('Without one, its links lead to /shop.', $campaign->filter('#landing-page')->text());
        self::assertSame(2, $campaign->filter('#landing-page input[name="template"]')->count(), 'Make the landing page from a template, here.');
        $labels = $campaign->filter('#figures .cl-ui-stat-card')->each(static fn (Crawler $card): string => $card->text());
        self::assertStringContainsString('Landing page visits', implode(' ', $labels));
        self::assertStringContainsString('Buttons pressed', implode(' ', $labels));

        $owner->submit($campaign->filter('#landing-page form')->form(['template' => 'several-offers']));
        $page = $this->landingPages()->forCampaign($this->campaigns()->require('spring-sale'));
        self::assertNotNull($page);
        self::assertSame('/marketing/landing-pages/' . $page->reference, $owner->getResponse()->headers->get('Location'));

        $campaign = $owner->request('GET', '/marketing/campaigns/spring-sale');
        self::assertStringContainsString('Draft', $campaign->filter('#landing-page')->text());
        self::assertStringContainsString('/c/spring-sale', $campaign->filter('#landing-page')->text());
        self::assertSame(['Preview', 'Edit'], $campaign->filter('#landing-page .cl-ui-section-head a')->each(static fn (Crawler $link): string => trim($link->text())));
        self::assertStringContainsString('/shop', $campaign->filter('#details')->text(), 'Its links still lead to its destination while the page is a draft.');

        $this->sections()->save($page->reference, $this->section($page, 'faq')->reference, ['items' => [['question' => 'Is it caffeine free?', 'answer' => 'Yes.']]]);
        $this->landingPages()->publish($page->reference);
        $campaign = $owner->request('GET', '/marketing/campaigns/spring-sale');
        self::assertStringContainsString('Its landing page, /c/spring-sale', $campaign->filter('#details')->text());
        self::assertSame(['View', 'Edit'], $campaign->filter('#landing-page .cl-ui-section-head a')->each(static fn (Crawler $link): string => trim($link->text())));
    }

    /** The spring sale, running, promoting the tea and the mug with the spring promotion. */
    private function springSale(): void
    {
        $this->campaigns()->create(new CampaignInput('Spring sale', 'spring-sale', destination: '/shop', promotion: 'SPRING10', offers: ['product:tea-100', 'product:mug-01']));
        $this->campaigns()->activate('spring-sale');
    }

    /** The spring sale with its page made from "Several offers": hero, offers, questions and answers, call to action. */
    private function campaignPage(): LandingPage
    {
        $this->springSale();

        return $this->landingPages()->createForCampaign('spring-sale', 'several-offers')->page;
    }

    private function section(LandingPage $page, string $kind): LandingSection
    {
        foreach ($this->landingPages()->sections($page) as $section) {
            if ($section->kindKey === $kind) {
                return $section;
            }
        }
        self::fail('The page has no ' . $kind . ' section.');
    }

    /** An Add a section card's words, by its kind. */
    private function card(Crawler $editor, string $kind): string
    {
        return trim($editor->filter('#landing-add input[value="' . $kind . '"]')->closest('label')?->filter('.cl-ui-picture-choice-label')->text() ?? '');
    }

    /** @return list<string> a version row's number, what changed and state */
    private function versionRow(Crawler $editor, int $index): array
    {
        $cells = $editor->filter('#versions tbody tr')->eq($index)->filter('td');

        return [trim($cells->eq(0)->text()), trim($cells->eq(2)->text()), trim($cells->eq(3)->text())];
    }

    /** @param list<string> $permissions */
    private function role(string $key, array $permissions, string $account): void
    {
        $roles = $this->container()->get(Roles::class);
        self::assertInstanceOf(Roles::class, $roles);
        $roles->save($key, 'Marketing helper', '', $permissions);
        $roles->grant($account, $key);
    }

    private function campaigns(): Campaigns
    {
        $campaigns = $this->container()->get(Campaigns::class);
        self::assertInstanceOf(Campaigns::class, $campaigns);

        return $campaigns;
    }

    private function landingPages(): LandingPages
    {
        $pages = $this->container()->get(LandingPages::class);
        self::assertInstanceOf(LandingPages::class, $pages);

        return $pages;
    }

    private function sections(): LandingSections
    {
        $sections = $this->container()->get(LandingSections::class);
        self::assertInstanceOf(LandingSections::class, $sections);

        return $sections;
    }

    private function landingTemplates(): LandingTemplates
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
