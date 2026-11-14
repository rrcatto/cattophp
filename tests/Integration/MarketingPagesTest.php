<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Access\Roles;
use CattoPHP\Activity\SubjectLinks;
use CattoPHP\Marketing\Adverts;
use CattoPHP\Marketing\CampaignInput;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Channels;
use CattoPHP\Marketing\MarketingPermissions;
use CattoPHP\Marketing\MarketingSubjectLinks;
use CattoPHP\Marketing\PublicationInput;
use CattoPHP\Marketing\Publications;
use CattoPHP\Marketing\Spend;
use CattoPHP\Settings\Settings;
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
 * The Campaign Marketing Manager's ready-made pages in a project that imports their route file, its records in the
 * database: every page and every action, allowed and refused, with and without each permission; a refused form back as
 * typed with why beside its field; the CSV downloads, the QR code and an advert's picture.
 */
final class MarketingPagesTest extends DatabaseTestCase
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

    public function testTheRouteFileGivesEachPageItsAddressAndNoRowNumber(): void
    {
        $router = $this->kernel->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $routes = $router->getRouteCollection();
        foreach ([
            'cattophp_marketing_overview' => ['/marketing', 'GET'], 'cattophp_marketing_overview_csv' => ['/marketing/figures.csv', 'GET'],
            'cattophp_marketing_campaigns' => ['/marketing/campaigns', 'GET'], 'cattophp_marketing_campaigns_csv' => ['/marketing/campaigns.csv', 'GET'],
            'cattophp_marketing_campaign_make' => ['/marketing/campaigns', 'POST'], 'cattophp_marketing_campaign_new' => ['/marketing/campaigns/new', 'GET'],
            'cattophp_marketing_campaign' => ['/marketing/campaigns/{code}', 'GET'], 'cattophp_marketing_campaign_save' => ['/marketing/campaigns/{code}', 'POST'],
            'cattophp_marketing_campaign_edit' => ['/marketing/campaigns/{code}/edit', 'GET'], 'cattophp_marketing_campaign_activate' => ['/marketing/campaigns/{code}/activate', 'POST'],
            'cattophp_marketing_campaign_pause' => ['/marketing/campaigns/{code}/pause', 'POST'], 'cattophp_marketing_campaign_archive' => ['/marketing/campaigns/{code}/archive', 'POST'],
            'cattophp_marketing_campaign_restore' => ['/marketing/campaigns/{code}/restore', 'POST'], 'cattophp_marketing_campaign_copy' => ['/marketing/campaigns/{code}/copy', 'POST'],
            'cattophp_marketing_campaign_delete' => ['/marketing/campaigns/{code}/delete', 'POST'], 'cattophp_marketing_spend_add' => ['/marketing/campaigns/{code}/spend', 'POST'],
            'cattophp_marketing_spend_remove' => ['/marketing/campaigns/{code}/spend/{reference}/remove', 'POST'],
            'cattophp_marketing_publication_new' => ['/marketing/campaigns/{code}/publications/new', 'GET'], 'cattophp_marketing_publication_make' => ['/marketing/campaigns/{code}/publications', 'POST'],
            'cattophp_marketing_publication' => ['/marketing/campaigns/{code}/publications/{publication}', 'GET'],
            'cattophp_marketing_publication_save' => ['/marketing/campaigns/{code}/publications/{publication}', 'POST'],
            'cattophp_marketing_publication_qr' => ['/marketing/campaigns/{code}/publications/{publication}/qr.svg', 'GET'],
            'cattophp_marketing_publication_retire' => ['/marketing/campaigns/{code}/publications/{publication}/retire', 'POST'],
            'cattophp_marketing_publication_restore' => ['/marketing/campaigns/{code}/publications/{publication}/restore', 'POST'],
            'cattophp_marketing_publication_delete' => ['/marketing/campaigns/{code}/publications/{publication}/delete', 'POST'],
            'cattophp_marketing_advert_new' => ['/marketing/campaigns/{code}/adverts/new', 'GET'], 'cattophp_marketing_advert_make' => ['/marketing/campaigns/{code}/adverts', 'POST'],
            'cattophp_marketing_advert' => ['/marketing/campaigns/{code}/adverts/{reference}', 'GET'], 'cattophp_marketing_advert_save' => ['/marketing/campaigns/{code}/adverts/{reference}', 'POST'],
            'cattophp_marketing_advert_picture' => ['/marketing/campaigns/{code}/adverts/{reference}/picture', 'GET'],
            'cattophp_marketing_advert_retire' => ['/marketing/campaigns/{code}/adverts/{reference}/retire', 'POST'],
            'cattophp_marketing_advert_restore' => ['/marketing/campaigns/{code}/adverts/{reference}/restore', 'POST'],
            'cattophp_marketing_advert_delete' => ['/marketing/campaigns/{code}/adverts/{reference}/delete', 'POST'],
            'cattophp_marketing_settings' => ['/marketing/settings', 'GET'], 'cattophp_marketing_settings_save' => ['/marketing/settings', 'POST'],
            'cattophp_marketing_settings_reset_group' => ['/marketing/settings/reset', 'POST'], 'cattophp_marketing_settings_reset' => ['/marketing/settings/reset/{name}', 'POST'],
            'cattophp_marketing_channel_add' => ['/marketing/settings/channels', 'POST'], 'cattophp_marketing_channel_save' => ['/marketing/settings/channels/{key}', 'POST'],
            'cattophp_marketing_channel_move' => ['/marketing/settings/channels/{key}/move', 'POST'], 'cattophp_marketing_channel_retire' => ['/marketing/settings/channels/{key}/retire', 'POST'],
            'cattophp_marketing_channel_restore' => ['/marketing/settings/channels/{key}/restore', 'POST'], 'cattophp_marketing_channel_delete' => ['/marketing/settings/channels/{key}/delete', 'POST'],
        ] as $name => [$path, $method]) {
            $route = $routes->get($name);
            self::assertNotNull($route, $name);
            self::assertSame([$path, [$method]], [$route->getPath(), $route->getMethods()], $name);
        }
        self::assertSame([], AddressContract::rowNumbers($routes), 'A campaign by its code, a publication by its code, an advert and a cost by their references.');
        $router->getContext()->setMethod('GET');
        self::assertSame('cattophp_marketing_campaign_new', $router->match('/marketing/campaigns/new')['_route'], 'New is no campaign\'s code.');
        self::assertSame('cattophp_marketing_publication_new', $router->match('/marketing/campaigns/spring-sale/publications/new')['_route']);
    }

    public function testAStrangerIsSentToSignInAndSomeoneWithoutThePermissionIsRefusedEverything(): void
    {
        $this->springSale();
        $advert = $this->advertWithPicture();
        $stranger = $this->browser();
        $stranger->request('GET', '/marketing');
        self::assertSame(['/sign-in?return=/marketing', 303], [$stranger->getResponse()->headers->get('Location'), $stranger->getResponse()->getStatusCode()]);
        $stranger->request('GET', '/marketing/campaigns/spring-sale/adverts/' . $advert . '/picture');
        self::assertSame(303, $stranger->getResponse()->getStatusCode(), 'A picture is never shown to a stranger.');

        $anna = $this->signedIn('anna@example.com');
        foreach (['/marketing', '/marketing/figures.csv', '/marketing/campaigns', '/marketing/campaigns.csv', '/marketing/campaigns/new', '/marketing/campaigns/spring-sale',
            '/marketing/campaigns/spring-sale/edit', '/marketing/campaigns/spring-sale/publications/new', '/marketing/campaigns/spring-sale/publications/fb1',
            '/marketing/campaigns/spring-sale/publications/fb1/qr.svg', '/marketing/campaigns/spring-sale/adverts/new', '/marketing/campaigns/spring-sale/adverts/' . $advert,
            '/marketing/campaigns/spring-sale/adverts/' . $advert . '/picture', '/marketing/settings'] as $page) {
            $anna->request('GET', $page);
            self::assertSame(403, $anna->getResponse()->getStatusCode(), $page . ': Anna holds no role that allows it.');
        }
        $token = $this->token($this->signedIn('owner@example.com')->request('GET', '/marketing/campaigns/spring-sale'));
        foreach (['/marketing/campaigns', '/marketing/campaigns/spring-sale', '/marketing/campaigns/spring-sale/pause', '/marketing/campaigns/spring-sale/archive',
            '/marketing/campaigns/spring-sale/copy', '/marketing/campaigns/spring-sale/delete', '/marketing/campaigns/spring-sale/spend',
            '/marketing/campaigns/spring-sale/publications', '/marketing/campaigns/spring-sale/publications/fb1/retire', '/marketing/campaigns/spring-sale/adverts',
            '/marketing/settings', '/marketing/settings/reset', '/marketing/settings/channels', '/marketing/settings/channels/print/retire'] as $action) {
            $anna->request('POST', $action, ['_token' => $token, 'name' => 'Taken over', 'label' => 'Taken over']);
            self::assertSame(403, $anna->getResponse()->getStatusCode(), $action);
        }
        self::assertSame(['Spring sale', 'active', 1], [$this->campaigns()->require('spring-sale')->name, $this->campaigns()->require('spring-sale')->status, $this->campaigns()->count()]);
        self::assertTrue($this->channels()->get('print')?->active);
    }

    public function testSomeoneWhoMayOnlyLookSeesEveryPageWithoutItsButtons(): void
    {
        $this->springSale();
        $advert = $this->advertWithPicture();
        $this->role('WATCHER', [MarketingPermissions::VIEW], '2');
        $anna = $this->signedIn('anna@example.com');
        foreach (['/marketing' => 'Campaign Marketing Manager', '/marketing/campaigns' => 'Campaigns', '/marketing/campaigns/spring-sale' => 'Spring sale',
            '/marketing/campaigns/spring-sale/publications/fb1' => 'Publication fb1', '/marketing/campaigns/spring-sale/adverts/' . $advert => 'Spring picture'] as $address => $heading) {
            $page = $anna->request('GET', $address);
            self::assertSame(200, $anna->getResponse()->getStatusCode(), $address);
            self::assertSame($heading, trim($page->filter('h1')->text()), $address);
            self::assertCount(0, $page->filter('form[method="post"]'), $address . ': no buttons for someone who may only look.');
            self::assertStringNotContainsString(' style=', (string) $anna->getResponse()->getContent(), $address . ': no inline style.');
        }
        $page = $anna->request('GET', '/marketing/campaigns/spring-sale/publications/fb1');
        self::assertStringContainsString('Facebook', $page->filter('#publication .cl-ui-key-value-list')->text(), 'Its facts instead of the form.');
        self::assertSame('https://example.com/c/spring-sale/fb1', $page->filter('#link-short')->attr('value'));
        foreach (['/marketing/campaigns/new', '/marketing/campaigns/spring-sale/edit', '/marketing/campaigns/spring-sale/publications/new', '/marketing/settings'] as $address) {
            $anna->request('GET', $address);
            self::assertSame(403, $anna->getResponse()->getStatusCode(), $address);
        }
        $anna->request('GET', '/marketing/campaigns/spring-sale/adverts/' . $advert . '/picture');
        self::assertSame([200, 'image/png'], [$anna->getResponse()->getStatusCode(), $anna->getResponse()->headers->get('Content-Type')], 'Someone who may look sees the picture.');
        self::assertStringContainsString('private', (string) $anna->getResponse()->headers->get('Cache-Control'));
        $anna->request('GET', '/marketing/campaigns/spring-sale/publications/fb1/qr.svg');
        $qr = self::statusOf($anna);
        $anna->request('GET', '/marketing/campaigns.csv');
        self::assertSame([200, 200], [$qr, self::statusOf($anna)], 'The QR code and the CSV too.');
    }

    public function testACampaignIsMadeRefusedAsTypedChangedAndItsCodeKeptOnceOpened(): void
    {
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/marketing/campaigns/new');
        self::assertSame(['New campaign', 1], [trim($page->filter('h1')->text()), $page->filter('input[name="offers[]"][value="product:tea-100"]')->count()]);
        self::assertCount(2, $page->filter('input[name="promotion"]'), 'None and the project\'s one promotion, as feature cards.');
        $owner->submit($page->filter('form[action="/marketing/campaigns"]')->form(), ['name' => 'Spring sale', 'code' => 'Not a code!', 'budget' => 'lots', 'ends_at' => '2026-02-30']);
        self::assertSame('/marketing/campaigns/new', $owner->getResponse()->headers->get('Location'));
        $page = $owner->followRedirect();
        self::assertStringContainsString('The campaign was not saved: see why beside each field.', $page->filter('.cl-flash-stack')->text());
        self::assertSame(['Spring sale', 'not a code!', 'lots'], [$page->filter('#campaign-name')->attr('value'), $page->filter('#campaign-code')->attr('value'), $page->filter('#campaign-budget')->attr('value')], 'As typed.');
        self::assertStringContainsString('A code is 2 to 40 small letters', $page->filter('#campaign-code-error')->text());
        self::assertStringContainsString('Type the budget as an amount', $page->filter('#campaign-budget-error')->text());
        self::assertSame(0, $this->campaigns()->count(), 'Nothing saved.');

        $owner->submit($page->filter('form[action="/marketing/campaigns"]')->form(), ['code' => 'new', 'budget' => '', 'ends_at' => '']);
        self::assertStringContainsString('kept for one of the pages', $owner->followRedirect()->filter('#campaign-code-error')->text(), 'New is the page that makes a campaign.');

        $page = $owner->request('GET', '/marketing/campaigns/new');
        $form = $page->filter('form[action="/marketing/campaigns"]')->form(['name' => 'Spring sale', 'code' => '', 'budget' => '1 500', 'starts_at' => '2026-11-01', 'ends_at' => '2026-11-30',
            'objective' => 'Sixty sales', 'promotion' => 'SPRING10']);
        $offers = $form['offers'];
        self::assertIsArray($offers);
        $first = $offers[0] ?? null;
        self::assertInstanceOf(\Symfony\Component\DomCrawler\Field\ChoiceFormField::class, $first);
        $first->tick();
        $owner->submit($form);
        self::assertSame('/marketing/campaigns/spring-sale', $owner->getResponse()->headers->get('Location'), 'Its code made from its name.');
        $page = $owner->followRedirect();
        self::assertStringContainsString('Spring sale is saved as a draft.', $page->filter('.cl-flash-stack')->text());
        self::assertSame(['Draft', '1 Nov 2026 to 30 Nov 2026', 'Budget R1,500', 'Code spring-sale'], $page->filter('.cl-page-head-badges .cl-ui-badge')->each(static fn (Crawler $badge): string => trim($badge->text())), 'Its head facts as badges.');
        self::assertStringContainsString('Rooibos tea, 100 bags', $page->filter('#offers')->text());
        self::assertStringContainsString('SPRING10: Spring, 10% off', $page->filter('#details')->text());
        self::assertSame('Campaign made', trim($page->filter('#history tbody td')->eq(2)->text()), 'Its history.');

        $page = $owner->request('GET', '/marketing/campaigns/spring-sale/edit');
        self::assertSame(['Spring sale', '2026-11-01', '2026-11-30', '1500'], [$page->filter('#campaign-name')->attr('value'), $page->filter('#campaign-starts')->attr('value'),
            $page->filter('#campaign-ends')->attr('value'), $page->filter('#campaign-budget')->attr('value')], 'As it is now.');
        self::assertNotNull($page->filter('input[name="offers[]"][value="product:tea-100"]')->attr('checked'));
        $owner->submit($page->filter('form[action="/marketing/campaigns/spring-sale"]')->form(), ['name' => 'Spring tea sale', 'code' => 'tea-spring']);
        self::assertSame('/marketing/campaigns/tea-spring', $owner->getResponse()->headers->get('Location'), 'Its code changes while nobody has opened its links.');
        self::assertStringContainsString('Spring tea sale is saved.', $owner->followRedirect()->filter('.cl-flash-stack')->text());

        $this->campaigns()->activate('tea-spring');
        $this->browser()->request('GET', '/c/tea-spring', server: ['HTTP_USER_AGENT' => self::BROWSER]);
        $page = $owner->request('GET', '/marketing/campaigns/tea-spring/edit');
        self::assertNotNull($page->filter('#campaign-code')->attr('readonly'), 'Its links are out in the world: its code stays.');
        $owner->request('GET', '/marketing/campaigns/no-such-campaign');
        self::assertSame(404, $owner->getResponse()->getStatusCode(), 'The site\'s ordinary not found.');
    }

    public function testACampaignIsActivatedPausedCopiedArchivedRestoredAndDeletedFromItsPage(): void
    {
        $this->campaigns()->create(new CampaignInput('Winter sale', 'winter-sale', budget: '900'));
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/marketing/campaigns/winter-sale');
        self::assertSame(['Activate', 'Edit', 'Copy', 'Delete', 'Every campaign'], $page->filter('.cl-page-head-actions .cl-ui-action')->each(static fn (Crawler $action): string => trim($action->text())),
            'A draft never published nor opened can be deleted.');
        $this->press($owner, $page, '/marketing/campaigns/winter-sale/activate');
        self::assertStringContainsString('Winter sale is now active.', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        $page = $owner->request('GET', '/marketing/campaigns/winter-sale');
        $this->press($owner, $page, '/marketing/campaigns/winter-sale/pause');
        self::assertStringContainsString('Winter sale is paused.', $owner->followRedirect()->filter('.cl-flash-stack')->text());

        $page = $owner->request('GET', '/marketing/campaigns/winter-sale');
        $this->press($owner, $page, '/marketing/campaigns/winter-sale/copy');
        self::assertSame('/marketing/campaigns/winter-sale-copy', $owner->getResponse()->headers->get('Location'));
        self::assertStringContainsString('A copy of Winter sale is saved as a draft, Winter sale (copy)', $owner->followRedirect()->filter('.cl-flash-stack')->text());

        $this->publications()->create('winter-sale', new PublicationInput('print', place: 'Library notice board'));
        $page = $owner->request('GET', '/marketing/campaigns/winter-sale');
        self::assertCount(0, $page->filter('form[action="/marketing/campaigns/winter-sale/delete"]'), 'Published: archived, never deleted.');
        $owner->request('POST', '/marketing/campaigns/winter-sale/delete', ['_token' => $this->token($page)]);
        self::assertStringContainsString('so it is kept: archive it instead', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        $page = $owner->request('GET', '/marketing/campaigns/winter-sale');
        $this->press($owner, $page, '/marketing/campaigns/winter-sale/archive');
        self::assertStringContainsString('Winter sale is archived', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        $page = $owner->request('GET', '/marketing/campaigns/winter-sale');
        self::assertSame('Archived', trim($page->filter('.cl-page-head-badges .cl-ui-badge')->first()->text()));
        $this->press($owner, $page, '/marketing/campaigns/winter-sale/restore');
        self::assertStringContainsString('Winter sale is restored, paused', $owner->followRedirect()->filter('.cl-flash-stack')->text());

        $page = $owner->request('GET', '/marketing/campaigns/winter-sale-copy');
        $this->press($owner, $page, '/marketing/campaigns/winter-sale-copy/delete');
        self::assertSame('/marketing/campaigns', $owner->getResponse()->headers->get('Location'));
        self::assertStringContainsString('Winter sale (copy) is deleted', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        self::assertNull($this->campaigns()->get('winter-sale-copy'));

        $owner->request('POST', '/marketing/campaigns/winter-sale/pause', ['_token' => 'not-the-token']);
        self::assertStringContainsString('That form had run out', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        self::assertSame('paused', $this->campaigns()->require('winter-sale')->status, 'Nothing done with a token run out.');
    }

    public function testAPublicationIsAddedWithItsLinksAndQrCodeThenChangedRetiredAndDeleted(): void
    {
        $this->campaigns()->create(new CampaignInput('Spring sale', 'spring-sale', destination: '/shop'));
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/marketing/campaigns/spring-sale/publications/new');
        self::assertSame('Add a publication', trim($page->filter('h1')->text()));
        self::assertCount(12, $page->filter('input[name="channel"]'), 'Every channel offered, as feature cards.');
        $owner->submit($page->filter('form[action="/marketing/campaigns/spring-sale/publications"]')->form(), ['place' => 'Tea lovers group', 'cost' => 'a lot']);
        $page = $owner->followRedirect();
        self::assertStringContainsString('Choose the channel', $page->filter('#publication-channel-error')->text());
        self::assertSame(['Tea lovers group', 'a lot'], [$page->filter('#publication-place')->attr('value'), $page->filter('#publication-cost')->attr('value')]);

        $owner->submit($page->filter('form[action="/marketing/campaigns/spring-sale/publications"]')->form(), ['channel' => 'facebook', 'cost' => '250', 'published_on' => '2026-11-02']);
        self::assertSame('/marketing/campaigns/spring-sale/publications/fb1#tracking-link', $owner->getResponse()->headers->get('Location'));
        $page = $owner->followRedirect();
        self::assertStringContainsString('fb1 is saved for Spring sale. Its tracking link is ready to copy.', $page->filter('.cl-flash-stack')->text());
        self::assertSame(['https://example.com/c/spring-sale/fb1', 'https://example.com/c/spring-sale?src=fb1'],
            [$page->filter('#link-short')->attr('value'), $page->filter('#link-source')->attr('value')], 'Ready to copy, without JavaScript too.');
        self::assertStringContainsString('utm_source=facebook', (string) $page->filter('#link-utm')->attr('value'));
        self::assertSame(['link-short', 'true'], [$page->filter('button[data-cl-copy]')->first()->attr('aria-controls'), $page->filter('button[data-cl-copy]')->first()->attr('hidden') === null ? 'false' : 'true'],
            'Its Copy button names its field and waits for the script.');
        self::assertCount(1, $page->filter('svg.cl-qr-code[role="img"]'), 'Its QR code on the page.');

        $owner->request('GET', '/marketing/campaigns/spring-sale/publications/fb1/qr.svg');
        $response = $owner->getResponse();
        self::assertSame(['image/svg+xml; charset=UTF-8', 'attachment; filename=spring-sale-fb1-qr.svg'], [$response->headers->get('Content-Type'), $response->headers->get('Content-Disposition')]);
        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<svg ', (string) $response->getContent());
        self::assertStringContainsString('<title>QR code of https://example.com/c/spring-sale/fb1</title>', (string) $response->getContent());

        $page = $owner->request('GET', '/marketing/campaigns/spring-sale/publications/fb1');
        $owner->submit($page->filter('form[action="/marketing/campaigns/spring-sale/publications/fb1"]')->form(), ['place' => 'Tea lovers group', 'code' => 'fbtea']);
        self::assertSame('/marketing/campaigns/spring-sale/publications/fbtea', $owner->getResponse()->headers->get('Location'));
        self::assertSame('Tea lovers group', $this->publications()->get($this->campaigns()->require('spring-sale'), 'fbtea')?->place);

        $page = $owner->request('GET', '/marketing/campaigns/spring-sale');
        self::assertSame('https://example.com/c/spring-sale/fbtea', $page->filter('#link-fbtea')->attr('value'), 'The campaign\'s page has each link to copy.');
        self::assertCount(1, $page->filter('a[href="/marketing/campaigns/spring-sale/publications/fbtea/qr.svg"]'), 'And its QR code.');
        $this->press($owner, $page, '/marketing/campaigns/spring-sale/publications/fbtea/retire');
        self::assertSame('/marketing/campaigns/spring-sale#publications', $owner->getResponse()->headers->get('Location'));
        self::assertStringContainsString('fbtea is retired.', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        $page = $owner->request('GET', '/marketing/campaigns/spring-sale/publications/fbtea');
        self::assertSame('Retired', trim($page->filter('.cl-page-head-badges .cl-ui-badge')->first()->text()));
        $this->press($owner, $page, '/marketing/campaigns/spring-sale/publications/fbtea/restore');
        self::assertSame('/marketing/campaigns/spring-sale/publications/fbtea', $owner->getResponse()->headers->get('Location'), 'Back where it was asked from.');
        $page = $owner->followRedirect();
        $this->press($owner, $page, '/marketing/campaigns/spring-sale/publications/fbtea/delete');
        self::assertSame('/marketing/campaigns/spring-sale#publications', $owner->getResponse()->headers->get('Location'), 'Deleted: on to its campaign.');
        self::assertNull($this->publications()->get($this->campaigns()->require('spring-sale'), 'fbtea'));
        $owner->request('GET', '/marketing/campaigns/spring-sale/publications/fbtea');
        self::assertSame(404, $owner->getResponse()->getStatusCode());
    }

    public function testAnAdvertIsAddedWithAPictureShownOnlyToThoseWhoMaySeeItAndRetired(): void
    {
        $this->campaigns()->create(new CampaignInput('Spring sale', 'spring-sale'));
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/marketing/campaigns/spring-sale/adverts/new');
        self::assertSame('multipart/form-data', $page->filter('form[action="/marketing/campaigns/spring-sale/adverts"]')->attr('enctype'));
        $owner->submit($page->filter('form[action="/marketing/campaigns/spring-sale/adverts"]')->form(), ['name' => 'Spring picture', 'kind' => 'picture', 'headline' => 'Tea for spring']);
        $page = $owner->followRedirect();
        self::assertStringContainsString('Choose the advert\'s picture', $page->filter('#advert-picture-error')->text());
        self::assertSame('Tea for spring', $page->filter('#advert-headline')->attr('value'));

        $upload = new UploadedFile(SamplePictures::file($this->folder, SamplePictures::PNG, 'spring.png'), 'spring.png', 'image/png', null, true);
        $owner->request('POST', '/marketing/campaigns/spring-sale/adverts', ['_token' => $this->token($page), 'name' => 'Spring picture', 'kind' => 'picture', 'headline' => 'Tea for spring'], ['picture' => $upload]);
        self::assertSame('/marketing/campaigns/spring-sale#adverts', $owner->getResponse()->headers->get('Location'));
        $page = $owner->followRedirect();
        self::assertStringContainsString('The advert Spring picture is saved for Spring sale.', $page->filter('.cl-flash-stack')->text());
        $picture = (string) $page->filter('#adverts .cl-ui-card img')->attr('src');
        self::assertMatchesRegularExpression('#^/marketing/campaigns/spring-sale/adverts/[a-z0-9]{8}/picture\?v=[0-9a-f]{12}$#', $picture);
        $owner->request('GET', $picture);
        self::assertSame([200, (string) base64_decode(SamplePictures::PNG)], [$owner->getResponse()->getStatusCode(), (string) file_get_contents($this->picturePath($owner))]);

        $reference = (string) preg_replace('#^.*/adverts/([a-z0-9]{8})/.*$#', '$1', $picture);
        $page = $owner->request('GET', '/marketing/campaigns/spring-sale/adverts/' . $reference);
        self::assertSame('Spring picture', trim($page->filter('h1')->text()));
        self::assertCount(1, $page->filter('input[name="remove_picture"]'), 'The picture can be removed.');
        $owner->submit($page->filter('form[action="/marketing/campaigns/spring-sale/adverts/' . $reference . '"]')->form(), ['name' => 'Spring picture, large']);
        self::assertStringContainsString('The advert Spring picture, large is saved.', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        $this->publications()->create('spring-sale', new PublicationInput('instagram', $reference));
        $page = $owner->request('GET', '/marketing/campaigns/spring-sale/adverts/' . $reference);
        self::assertCount(0, $page->filter('form[action$="/delete"]'), 'Published: retired, never deleted.');
        $this->press($owner, $page, '/marketing/campaigns/spring-sale/adverts/' . $reference . '/retire');
        self::assertStringContainsString('is retired', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        self::assertTrue($this->adverts()->get($reference)?->isRetired());

        $other = $this->campaigns()->create(new CampaignInput('Winter sale', 'winter-sale'));
        $owner->request('GET', '/marketing/campaigns/' . $other->code . '/adverts/' . $reference);
        self::assertSame(404, $owner->getResponse()->getStatusCode(), 'Another campaign\'s advert is simply not found.');
        $owner->request('GET', '/marketing/campaigns/' . $other->code . '/adverts/' . $reference . '/picture');
        self::assertSame(404, $owner->getResponse()->getStatusCode());
    }

    public function testACostIsAddedRefusedAsTypedAndRemoved(): void
    {
        $this->campaigns()->create(new CampaignInput('Spring sale', 'spring-sale', budget: '500'));
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/marketing/campaigns/spring-sale');
        $owner->submit($page->filter('form[action="/marketing/campaigns/spring-sale/spend"]')->form(), ['amount' => 'nothing', 'note' => 'Flyer design']);
        self::assertSame('/marketing/campaigns/spring-sale#spend', $owner->getResponse()->headers->get('Location'));
        $page = $owner->followRedirect();
        self::assertSame(['Flyer design', 'open'], [$page->filter('#cost-note')->attr('value'), $page->filter('#spend details')->attr('open') === null ? 'closed' : 'open']);
        self::assertStringContainsString('Type what was spent', $page->filter('#cost-amount-error')->text());
        $owner->submit($page->filter('form[action="/marketing/campaigns/spring-sale/spend"]')->form(), ['amount' => '650', 'spent_on' => '2026-11-03', 'note' => 'Flyer design']);
        $page = $owner->followRedirect();
        self::assertStringContainsString('A cost of R650 is added to Spring sale.', $page->filter('.cl-flash-stack')->text());
        self::assertStringContainsString('R650 spent of R500 (130%).', $page->filter('#spend')->text());
        self::assertCount(1, $page->filter('#spend .cl-ui-badge'), 'Over budget.');
        $remove = $page->filter('#spend form[action$="/remove"]');
        self::assertCount(1, $remove);
        $owner->submit($remove->form());
        self::assertStringContainsString('The cost of R650 on 3 Nov 2026 is removed from Spring sale.', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        self::assertSame([], $this->spend()->list($this->campaigns()->require('spring-sale')->id));
        $owner->request('POST', '/marketing/campaigns/spring-sale/spend/abcdefgh/remove', ['_token' => $this->token($page)]);
        self::assertStringContainsString('That cost is not one of Spring sale\'s now', $owner->followRedirect()->filter('.cl-flash-stack')->text());
    }

    public function testTheCampaignsListFiltersSearchesSortsAndDownloads(): void
    {
        $this->springSale();
        $this->campaigns()->create(new CampaignInput('Winter warmers', 'winter-warmers', description: 'Mugs for the cold'));
        $this->campaigns()->archive($this->campaigns()->create(new CampaignInput('Old autumn sale', 'old-autumn'))->code);
        $this->browser()->request('GET', '/c/spring-sale/fb1', server: ['HTTP_USER_AGENT' => self::BROWSER]);
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/marketing/campaigns');
        self::assertSame(['Spring sale', 'Winter warmers'], self::sorted($page->filter('#campaigns-results tbody td:first-child a')->each(static fn (Crawler $a): string => $a->text())), 'Current: every one not archived.');
        self::assertSame('current', $page->filter('input[name="status"][checked]')->attr('value'), 'The status as feature cards.');
        $page = $owner->request('GET', '/marketing/campaigns?status=archived');
        self::assertSame(['Old autumn sale'], $page->filter('#campaigns-results tbody td:first-child a')->each(static fn (Crawler $a): string => $a->text()));
        $page = $owner->request('GET', '/marketing/campaigns?status=all&campaigns_q=mugs');
        self::assertSame(['Winter warmers'], $page->filter('#campaigns-results tbody td:first-child a')->each(static fn (Crawler $a): string => $a->text()), 'Searched by description too.');
        $page = $owner->request('GET', '/marketing/campaigns?status=all&campaigns_sort=clicks&campaigns_dir=desc');
        self::assertSame('Spring sale', $page->filter('#campaigns-results tbody td:first-child a')->first()->text());
        self::assertSame('descending', $page->filter('th[aria-sort]')->attr('aria-sort'));
        self::assertSame(['1', '1'], [trim($page->filter('#campaigns-results tbody tr')->first()->filter('td')->eq(3)->text()), trim($page->filter('#campaigns-results tbody tr')->first()->filter('td')->eq(4)->text())],
            'Its publication and its click.');
        $page = $owner->request('GET', '/marketing/campaigns?status=paused');
        self::assertStringContainsString('No campaign has this status now.', $page->filter('#campaigns-results')->text());

        $owner->request('GET', '/marketing/campaigns.csv?status=all&campaigns_sort=name&campaigns_dir=asc');
        $csv = (string) $this->content($owner);
        self::assertStringStartsWith("\u{FEFF}Code,Name,Status,Starts,Ends,Publications,Clicks,\"Unique visitors\",Spend,Budget", $csv);
        self::assertStringContainsString('old-autumn,"Old autumn sale",Archived', $csv);
        self::assertStringContainsString('spring-sale,"Spring sale",Active,,,1,1,1,250.00,', $csv);
        self::assertMatchesRegularExpression('/^attachment; filename=campaigns-\d{4}-\d{2}-\d{2}\.csv$/', (string) $owner->getResponse()->headers->get('Content-Disposition'));
        $owner->request('GET', '/marketing/campaigns');
        $page = $owner->request('GET', '/marketing/campaigns?status=all&campaigns_q=nothing-like-it');
        self::assertStringContainsString('No campaign matches', $page->filter('#campaigns-results')->text());
    }

    public function testTheOverviewShowsTheFiguresComparesThemAndDownloadsThem(): void
    {
        $this->springSale();
        $this->publications()->create('spring-sale', new PublicationInput('email', place: 'November letter'));
        $visitor = $this->browser();
        $visitor->request('GET', '/c/spring-sale/fb1', server: ['HTTP_USER_AGENT' => self::BROWSER, 'REMOTE_ADDR' => '198.51.100.7']);
        $this->browser()->request('GET', '/c/spring-sale/em1', server: ['HTTP_USER_AGENT' => self::BROWSER]);
        $this->browser()->request('GET', '/c/spring-sale/fb1', server: ['HTTP_USER_AGENT' => 'WhatsApp/2.24.1.6 A']);
        $this->campaigns()->create(new CampaignInput('Winter sale', 'winter-sale'));
        $this->campaigns()->activate('winter-sale');
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/marketing');
        self::assertSame('Campaign Marketing Manager', trim($page->filter('h1')->text()));
        self::assertStringContainsString('Winter sale', $page->filter('#attention')->text(), 'Active with no publication needs attention.');
        self::assertSame(['2', '2', '0', '1', 'R250'], $page->filter('#period .cl-ui-stat-value')->each(static fn (Crawler $value): string => trim($value->text())),
            'Clicks, unique visitors, repeat visits, link previews set aside, spend.');
        self::assertSame('30d', $page->filter('input[name="period"][checked]')->attr('value'));
        self::assertSame(['Spring sale'], $page->filter('#figures-results tbody td:first-child a')->each(static fn (Crawler $a): string => $a->text()), 'By campaign: Winter sale has nothing yet.');
        $page = $owner->request('GET', '/marketing?by=channel&period=all');
        self::assertSame(['Email', 'Facebook'], self::sorted($page->filter('#figures-results tbody td:first-child')->each(static fn (Crawler $cell): string => trim($cell->text()))));
        $page = $owner->request('GET', '/marketing?by=channel&period=all&figures_sort=spend&figures_dir=desc');
        self::assertSame('Facebook', trim($page->filter('#figures-results tbody td:first-child')->first()->text()), 'Sorted by spend.');
        self::assertStringContainsString('Unknown 2 clicks 2 unique visitors', $page->filter('#countries')->text(), 'With no network lookup, every country is unknown.');
        self::assertCount(1, $page->filter('#over-time .cl-ui-column-chart'));
        $page = $owner->request('GET', '/marketing?by=publication&period=dates&from=2026-01-01&to=2026-01-31');
        self::assertStringContainsString('1 Jan 2026 to 31 Jan 2026', $page->filter('#period-heading')->text());
        self::assertStringContainsString('Nothing to compare yet', $page->filter('#figures')->text());
        $page = $owner->request('GET', '/marketing?period=dates&from=2026-12-31&to=2026-01-01');
        self::assertStringContainsString('Those dates could not be read', $page->filter('#period')->text());

        $owner->request('GET', '/marketing/figures.csv?by=channel&period=all&figures_sort=label&figures_dir=asc');
        $csv = (string) $this->content($owner);
        self::assertStringStartsWith("\u{FEFF}Channel,\"Belongs to\",Clicks,\"Unique visitors\",\"Repeat visits\",\"Set aside\",Spend", $csv);
        self::assertStringContainsString("Facebook,,1,1,0,1,250.00", $csv);
        self::assertMatchesRegularExpression('/^attachment; filename=marketing-channels-\d{4}-\d{2}-\d{2}\.csv$/', (string) $owner->getResponse()->headers->get('Content-Disposition'));
    }

    public function testTheSettingsAreSavedAndResetHereAndTheChannelsChanged(): void
    {
        $this->springSale();
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/marketing/settings');
        self::assertSame('Marketing settings', trim($page->filter('h1')->text()));
        self::assertCount(2, $page->filter('input[name="MARKETING_SALE_COUNTS_FOR"]'), 'A sale counts for: feature cards.');
        self::assertCount(2, $page->filter('input[type="radio"][name="MARKETING_HONOUR_DO_NOT_TRACK"]'), 'Honouring Do Not Track: two feature cards, never a tick box.');
        self::assertSame('true', $page->filter('input[name="MARKETING_HONOUR_DO_NOT_TRACK"][checked]')->attr('value'));
        self::assertStringContainsString('Its default: .env gives nothing for MARKETING_SAME_VISIT.', $page->filter('#setting-marketing-same-visit-help')->text());
        $form = $page->filter('form[action="/marketing/settings"]')->form();
        $owner->submit($form, ['MARKETING_SAME_VISIT' => 'soon']);
        self::assertSame('/marketing/settings#settings-marketing', $owner->getResponse()->headers->get('Location'));
        $page = $owner->followRedirect();
        self::assertStringContainsString('The settings were not saved', $page->filter('.cl-flash-stack')->text());
        self::assertSame('soon', $page->filter('#setting-marketing-same-visit')->attr('value'), 'As typed.');
        self::assertNotSame('', trim($page->filter('#setting-marketing-same-visit-error')->text()));
        $owner->submit($page->filter('form[action="/marketing/settings"]')->form(), ['MARKETING_SAME_VISIT' => '45m', 'MARKETING_SALE_COUNTS_FOR' => 'first', 'MARKETING_HONOUR_DO_NOT_TRACK' => 'false']);
        self::assertStringContainsString('Campaign Marketing Manager: 3 settings saved.', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        self::assertSame(['45m', 'first', 'false'], [$this->settings()->get('MARKETING_SAME_VISIT'), $this->settings()->get('MARKETING_SALE_COUNTS_FOR'), $this->settings()->get('MARKETING_HONOUR_DO_NOT_TRACK')]);
        $page = $owner->request('GET', '/marketing/settings');
        self::assertSame('first', $page->filter('input[name="MARKETING_SALE_COUNTS_FOR"][checked]')->attr('value'));
        $owner->submit($page->filter('form[action="/marketing/settings/reset/MARKETING_SAME_VISIT"]')->form());
        self::assertStringContainsString('Openings of the same link count as one visit within is back to its default.', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        $page = $owner->request('GET', '/marketing/settings');
        $owner->submit($page->filter('form[action="/marketing/settings/reset"]')->form());
        self::assertStringContainsString('every setting is back', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        self::assertSame(['30m', 'last', 'true'], [$this->settings()->get('MARKETING_SAME_VISIT'), $this->settings()->get('MARKETING_SALE_COUNTS_FOR'), $this->settings()->get('MARKETING_HONOUR_DO_NOT_TRACK')]);

        $page = $owner->request('GET', '/marketing/settings');
        $owner->submit($page->filter('#channel-add form')->form(), ['label' => 'Facebook', 'link_prefix' => 'fb']);
        $page = $owner->followRedirect();
        self::assertStringContainsString('There is already a channel with the key facebook.', $page->filter('#channel-add-label-error')->text(), 'A key taken is the name\'s fault.');
        self::assertStringContainsString('Another channel has the prefix fb.', $page->filter('#channel-add-prefix-error')->text());
        self::assertNotNull($page->filter('#channel-add')->attr('open'), 'Open again, as typed.');
        $owner->submit($page->filter('#channel-add form')->form(), ['label' => 'Local radio', 'link_prefix' => 'ra', 'icon' => 'radio']);
        self::assertSame('/marketing/settings#channels', $owner->getResponse()->headers->get('Location'));
        $page = $owner->followRedirect();
        self::assertStringContainsString('Local radio is added to the channels, last in the list.', $page->filter('.cl-flash-stack')->text());
        self::assertSame('Local radio', trim($page->filter('#channels tbody tr')->last()->filter('td')->first()->text()));
        self::assertCount(0, $page->filter('#channels tbody tr')->last()->filter('form[action$="/move"] button[value="down"]'), 'The last has no Down.');

        $this->press($owner, $page, '/marketing/settings/channels/local-radio/move');
        self::assertStringContainsString('Local radio is moved up.', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        self::assertSame(['local-radio', 'other'], array_slice(array_map(static fn ($channel): string => $channel->key, $this->channels()->all()), -2));
        $page = $owner->request('GET', '/marketing/settings?channel=local-radio');
        self::assertSame(['Change Local radio', 'Local radio'], [trim($page->filter('#channel-edit h3')->text()), $page->filter('#channel-edit-label')->attr('value')]);
        $owner->submit($page->filter('#channel-edit')->form(), ['label' => 'Community radio', 'icon' => 'mic']);
        self::assertStringContainsString('Local radio is now called Community radio.', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        $page = $owner->request('GET', '/marketing/settings');
        $this->press($owner, $page, '/marketing/settings/channels/local-radio/retire');
        self::assertStringContainsString('Community radio is retired', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        $page = $owner->request('GET', '/marketing/settings');
        $this->press($owner, $page, '/marketing/settings/channels/local-radio/restore');
        self::assertTrue($this->channels()->get('local-radio')?->active);
        $page = $owner->followRedirect();
        self::assertCount(0, $page->filter('form[action="/marketing/settings/channels/facebook/delete"]'), 'Facebook has a publication: retired, never deleted.');
        $owner->request('POST', '/marketing/settings/channels/facebook/delete', ['_token' => $this->token($page)]);
        self::assertStringContainsString('Facebook has publications, so it is kept: retire it instead.', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        $page = $owner->request('GET', '/marketing/settings');
        $this->press($owner, $page, '/marketing/settings/channels/local-radio/delete');
        self::assertStringContainsString('Community radio is deleted.', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        self::assertNull($this->channels()->get('local-radio'));

        $this->role('WATCHER', [MarketingPermissions::VIEW, MarketingPermissions::MANAGE], '2');
        $anna = $this->signedIn('anna@example.com');
        $anna->request('GET', '/marketing/settings');
        self::assertSame(403, $anna->getResponse()->getStatusCode(), 'The settings need their own permission.');
    }

    public function testTheMenuShowsEachPageToThoseWhoMayOpenItAndTheLogLinksACampaign(): void
    {
        $this->springSale();
        $owner = $this->signedIn('owner@example.com');
        $owner->request('GET', '/marketing/campaigns');
        $menus = $this->container()->get(Menus::class);
        self::assertInstanceOf(Menus::class, $menus);
        $section = $menus->sections()[0] ?? [];
        self::assertSame(['marketing', 'Marketing', 'nav-marketing'], [$section['key'] ?? '', $section['label'] ?? '', $section['icon'] ?? '']);
        self::assertSame([['Overview', '/marketing'], ['Campaigns', '/marketing/campaigns'], ['Landing pages', '/marketing/landing-pages'], ['Visitors', '/marketing/visitors'],
            ['Sales', '/marketing/sales'], ['Settings', '/marketing/settings']],
            array_map(static fn (array $entry): array => [$entry['label'], $entry['href']], $section['entries'] ?? []));

        $links = null;
        foreach ($this->kernelLinks() as $candidate) {
            if ($candidate instanceof MarketingSubjectLinks) {
                $links = $candidate;
            }
        }
        self::assertInstanceOf(MarketingSubjectLinks::class, $links, 'Found among the log\'s links.');
        self::assertSame(['/marketing/campaigns/spring-sale', null, '/marketing/settings#channels', null], [$links->href('marketing_campaign', 'spring-sale'),
            $links->href('marketing_campaign', 'gone-campaign'), $links->href('marketing_channel', 'facebook'), $links->href('garden_tool', 'x')]);
        $page = $owner->request('GET', '/admin/activity?action=marketing.publication_created');
        self::assertCount(1, $page->filter('a[href="/marketing/campaigns/spring-sale"]'), 'The activity log links the campaign.');
        self::assertStringContainsString('Channel: Facebook', $page->filter('tbody')->text(), 'Its details in words.');
        $page = $owner->request('GET', '/marketing/campaigns/spring-sale');
        self::assertStringContainsString('Status: Active', $page->filter('#history')->text());
        self::assertStringContainsString('Publication added', $page->filter('#history')->text());
    }

    /** Spring sale, active, leading to /shop, published on Facebook as fb1 for R250. */
    private function springSale(): void
    {
        $this->campaigns()->create(new CampaignInput('Spring sale', 'spring-sale', destination: '/shop'));
        $this->campaigns()->activate('spring-sale');
        $this->publications()->create('spring-sale', new PublicationInput('facebook', cost: '250', place: 'Tea lovers group'));
    }

    /** A picture advert of the spring sale: its reference. */
    private function advertWithPicture(): string
    {
        return $this->adverts()->create('spring-sale', new \CattoPHP\Marketing\AdvertInput('Spring picture', 'picture', 'Tea for spring',
            picturePath: SamplePictures::file($this->folder, SamplePictures::PNG)))->reference;
    }

    /** @param list<string> $permissions */
    private function role(string $key, array $permissions, string $account): void
    {
        $roles = $this->container()->get(Roles::class);
        self::assertInstanceOf(Roles::class, $roles);
        $roles->save($key, 'Marketing helper', '', $permissions);
        $roles->grant($account, $key);
    }

    /** Presses the button of the first form posting to $action on the page, its name and value sent as a browser does. */
    private function press(KernelBrowser $browser, Crawler $page, string $action): void
    {
        $button = $page->filter('form[action="' . $action . '"] button');
        self::assertGreaterThan(0, $button->count(), 'A form posts to ' . $action . '.');
        $browser->submit($button->first()->form());
    }

    private function picturePath(KernelBrowser $browser): string
    {
        $response = $browser->getResponse();
        self::assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $response);

        return $response->getFile()->getPathname();
    }

    private function content(KernelBrowser $browser): string
    {
        return $browser->getInternalResponse()->getContent();
    }

    /** @return iterable<SubjectLinks> */
    private function kernelLinks(): iterable
    {
        $links = $this->container()->get(MarketingSubjectLinks::class);
        self::assertInstanceOf(SubjectLinks::class, $links);

        return [$links];
    }

    private function campaigns(): Campaigns
    {
        $campaigns = $this->container()->get(Campaigns::class);
        self::assertInstanceOf(Campaigns::class, $campaigns);

        return $campaigns;
    }

    private function publications(): Publications
    {
        $publications = $this->container()->get(Publications::class);
        self::assertInstanceOf(Publications::class, $publications);

        return $publications;
    }

    private function adverts(): Adverts
    {
        $adverts = $this->container()->get(Adverts::class);
        self::assertInstanceOf(Adverts::class, $adverts);

        return $adverts;
    }

    private function channels(): Channels
    {
        $channels = $this->container()->get(Channels::class);
        self::assertInstanceOf(Channels::class, $channels);

        return $channels;
    }

    private function spend(): Spend
    {
        $spend = $this->container()->get(Spend::class);
        self::assertInstanceOf(Spend::class, $spend);

        return $spend;
    }

    private function settings(): Settings
    {
        $settings = $this->container()->get(Settings::class);
        self::assertInstanceOf(Settings::class, $settings);
        $settings->refresh();

        return $settings;
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

    /** The last answer's status, read afresh (a browser's answer changes with each request). */
    private static function statusOf(KernelBrowser $browser): int
    {
        return $browser->getResponse()->getStatusCode();
    }

    private function token(Crawler $page): string
    {
        return (string) $page->filter('input[name="_token"]')->first()->attr('value');
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
