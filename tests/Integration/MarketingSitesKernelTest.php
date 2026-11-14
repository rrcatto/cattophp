<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Access\Roles;
use CattoPHP\Marketing\CampaignInput;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\MarketingPermissions;
use CattoPHP\Marketing\MarketingSubjectLinks;
use CattoPHP\Marketing\PublicationInput;
use CattoPHP\Marketing\Publications;
use CattoPHP\Marketing\Sales\ReportedSale;
use CattoPHP\Marketing\Sales\SaleLine;
use CattoPHP\Marketing\Sales\Sales;
use CattoPHP\Marketing\Sites\SiteForward;
use CattoPHP\Marketing\Sites\SiteReports;
use CattoPHP\Marketing\Sites\Sites;
use CattoPHP\Marketing\Touches;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\MarketingSitesResponses;
use CattoPHP\Tests\Support\RecordingLogger;
use CattoPHP\Tests\Support\RecordingTransport;
use CattoPHP\Webhooks\WebhookSignature;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\RouterInterface;

/**
 * Connected sites in a project with the marketing module on: a tracking link to a connected site forwarding with a
 * signed reference (a preview, another site and a secret that cannot be read forwarding plainly); an arrival by another
 * site's link recorded once with its campaign made, its cookie given and the address cleaned, a replay, a stale and a
 * forged one recording nothing and naming why on the card, and the same visit at this site's own tracking link; the
 * reports endpoint refusing every unsigned message bare, skipping repeats, keeping unknown clicks and recording events
 * and sales; and the Connected sites card on the settings page, for those who may manage the settings and refused to
 * those who may not.
 */
final class MarketingSitesKernelTest extends DatabaseTestCase
{
    private const PERSON = 'Mozilla/5.0 (X11; Linux x86_64; rv:146.0) Gecko/20100101 Firefox/146.0';
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

    public function testTheRoutesAreTheCardsActionsAndTheTwoWebhooks(): void
    {
        $router = $this->kernel->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        foreach ([
            'cattophp_marketing_site_add' => ['/marketing/settings/sites', 'POST'], 'cattophp_marketing_site_save' => ['/marketing/settings/sites/{key}', 'POST'],
            'cattophp_marketing_site_check' => ['/marketing/settings/sites/{key}/check', 'POST'], 'cattophp_marketing_site_secret' => ['/marketing/settings/sites/{key}/secret', 'POST'],
            'cattophp_marketing_site_send' => ['/marketing/settings/sites/{key}/send', 'POST'], 'cattophp_marketing_site_remove' => ['/marketing/settings/sites/{key}/remove', 'POST'],
            'cattophp_marketing_sites_receive' => ['/webhooks/marketing', 'POST'], 'cattophp_marketing_sites_check' => ['/webhooks/marketing/check', 'GET'],
        ] as $name => [$path, $method]) {
            $route = $router->getRouteCollection()->get($name);
            self::assertNotNull($route, $name);
            self::assertSame([$path, [$method]], [$route->getPath(), $route->getMethods()], $name);
        }
    }

    public function testALinkToAConnectedSiteForwardsAPersonWithASignedReferenceAndNothingAboutThem(): void
    {
        $this->sites()->add('Example Shop', 'https://example.org', self::SECRET);
        $this->campaign('law-nov26', 'Law, November', 'https://example.org/shop?from=us#top');
        $this->publications()->create('law-nov26', new PublicationInput('email'));
        $person = $this->browser();
        $person->setServerParameter('HTTP_USER_AGENT', self::PERSON);
        $person->request('GET', '/c/law-nov26/em1?gclid=abc');
        $location = (string) $person->getResponse()->headers->get('Location');
        self::assertSame(1, preg_match('#^https://example\.org/shop\?from=us&cattophp_from=([A-Za-z0-9_-]+)&cattophp_sig=([^&\#]+)\#top$#', $location, $found), $location);
        $payload = $found[1] ?? '';
        $values = SiteForward::decode($payload) ?? self::fail();
        $touch = $this->touches()->forCampaign($this->campaigns()->require('law-nov26')->id)[0] ?? self::fail();
        self::assertSame(['v' => 1, 'site' => 'https://example.com', 'campaign' => 'law-nov26', 'name' => 'Law, November', 'publication' => 'em1', 'channel' => 'email',
            'click' => $touch->reference], $values, 'Codes and the click\'s reference: no person, no IP address, nothing a visitor typed.');
        self::assertTrue(WebhookSignature::valid(rawurldecode($found[2] ?? ''), $payload, [self::SECRET], time()));

        $preview = $this->browser();
        $preview->setServerParameter('HTTP_USER_AGENT', 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)');
        $preview->request('GET', '/c/law-nov26/em1');
        self::assertSame('https://example.org/shop?from=us#top', $preview->getResponse()->headers->get('Location'), 'A preview forwards without them.');

        $this->campaign('tea', 'Tea', 'https://example.net/tea');
        $person->request('GET', '/c/tea');
        self::assertSame('https://example.net/tea', $person->getResponse()->headers->get('Location'), 'An address on no connected site, as it was.');

        $this->connection->executeStatement("UPDATE cattophp_marketing_sites SET secret = 'v1:" . base64_encode(str_repeat('x', 40)) . "'");
        $person->request('GET', '/c/law-nov26/em1');
        self::assertSame('https://example.org/shop?from=us#top', $person->getResponse()->headers->get('Location'), 'A secret that cannot be read: plainly, and the click still counts.');
        self::assertCount(3, $this->touches()->forCampaign($this->campaigns()->require('law-nov26')->id));
        self::assertNotEmpty(array_filter($this->logger()->lines, static fn (string $line): bool => str_contains($line, 'forwarded without its signed reference')));
    }

    public function testAnArrivalIsRecordedOnceWithItsCampaignMadeAndTheAddressCleaned(): void
    {
        $this->sites()->add('Example Org', 'https://example.org', self::SECRET);
        $person = $this->browser();
        $person->setServerParameter('HTTP_USER_AGENT', self::PERSON);
        $person->setServerParameter('REMOTE_ADDR', '198.51.100.7');
        $arrival = $this->arrival(['click' => 'k2m9q4d7xa'], time(), '/open/form?size=large');
        $person->request('GET', $arrival);
        $answer = $person->getResponse();
        self::assertSame([302, '/open/form?size=large', 'no-store, private'], [$answer->getStatusCode(), $answer->headers->get('Location'), $answer->headers->get('Cache-Control')],
            'Back to the same address without the two values.');
        $cookie = $person->getCookieJar()->get('cattophp_visitor');
        self::assertNotNull($cookie, 'A person is given the visitor cookie, as by a tracking link.');
        $campaign = $this->campaigns()->require('law-nov26');
        self::assertSame(['Law, November', 'active', 'example-org', 'Made when a visitor arrived by a link from Example Org.', '/'], [$campaign->name, $campaign->status, $campaign->originSite,
            $campaign->description, $campaign->destination]);
        $touch = $this->touches()->byOrigin('example-org', 'k2m9q4d7xa') ?? self::fail();
        self::assertSame([null, 'email', 'em1', '/open/form?size=large', true, 'person'], [$touch->publicationId, $touch->source, $touch->originPublication, $touch->landing, $touch->counted,
            $touch->agent]);
        self::assertNotNull($this->sites()->get('example-org')?->lastReceivedAt);

        $person->request('GET', $arrival);
        self::assertSame('/open/form?size=large', $person->getResponse()->headers->get('Location'));
        self::assertCount(1, $this->touches()->forCampaign($campaign->id), 'A replay records nothing more.');

        // This site's own tracking link for the same campaign, just after: the same visit, not a second click.
        $person->request('GET', '/c/law-nov26');
        $touches = $this->touches()->forCampaign($campaign->id);
        self::assertCount(2, $touches);
        self::assertSame([false, 'same_visit'], [$touches[0]->counted, $touches[0]->notCountedBecause]);
    }

    public function testAStaleForgedOrUnknownArrivalRecordsNothingAndOnlyTheCardSaysWhy(): void
    {
        $this->sites()->add('Example Org', 'https://example.org', self::SECRET);
        $person = $this->browser();
        $person->setServerParameter('HTTP_USER_AGENT', self::PERSON);
        $person->request('GET', $this->arrival(['click' => 'k2m9q4d7xa'], time() - 420));
        self::assertSame([302, '/open/form'], [$person->getResponse()->getStatusCode(), $person->getResponse()->headers->get('Location')]);
        self::assertNull($person->getCookieJar()->get('cattophp_visitor'), 'Nothing kept, and nothing said.');
        self::assertSame('arrival: its time was 7 minutes from this site\'s.', $this->sites()->get('example-org')?->lastRefused);

        $person->request('GET', $this->arrival(['click' => 'k2m9q4d7xb'], time(), '/open/form', 'someone-elses-secret-of-thirty-two-characters'));
        self::assertSame('/open/form', $person->getResponse()->headers->get('Location'));
        self::assertSame('arrival: its signature did not match.', $this->sites()->get('example-org')?->lastRefused);

        $person->request('GET', $this->arrival(['site' => 'https://example.net', 'click' => 'k2m9q4d7xc'], time()));
        self::assertSame('/open/form', $person->getResponse()->headers->get('Location'));
        $person->request('GET', '/open/form?cattophp_from=rubbish&cattophp_sig=t%3D1');
        self::assertSame('/open/form', self::location($person), 'Nothing a connected site makes: cleaned all the same.');
        self::assertNull($this->campaigns()->get('law-nov26'), 'No campaign made.');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_marketing_touches'));

        $owner = $this->signedIn('owner@example.com');
        $card = $owner->request('GET', '/marketing/settings')->filter('#site-example-org');
        self::assertStringContainsString('Last refused arrival, ', $card->text());
        self::assertStringContainsString(': its signature did not match.', $card->text());
    }

    public function testTheReportsEndpointRefusesEveryUnsignedMessageBareAndRecordsTheRestOnce(): void
    {
        $this->sites()->add('Example Shop', 'https://example.org', self::SECRET);
        $this->campaign('spring-sale', 'Spring sale', 'https://example.org/shop');
        $this->publications()->create('spring-sale', new PublicationInput('facebook'));
        $person = $this->browser();
        $person->setServerParameter('HTTP_USER_AGENT', self::PERSON);
        $person->request('GET', '/c/spring-sale/fb1');
        $click = $this->touches()->forCampaign($this->campaigns()->require('spring-sale')->id)[0] ?? self::fail();
        $now = new DateTimeImmutable();
        $paid = (new ReportedSale('sale_paid:CL-00010482', 'sale_paid', $now, (string) $click->reference, true, true, 'CL-00010482', 'ZAR', 'paid', $now->modify('-1 hour'), $now, 150000, 0, 0,
            [new SaleLine('line:1', 'course', 'fin-101', 'Bookkeeping basics', 'Course', 1, 150000)]))->body();
        $reports = [
            ['version' => 1, 'key' => 'registered:41', 'kind' => 'registered', 'at' => $now->format(DATE_ATOM), 'click' => $click->reference, 'offer' => ['type' => 'list', 'reference' => 'news', 'label' => 'News']],
            ['version' => 1, 'key' => 'registered:41', 'kind' => 'registered', 'at' => $now->format(DATE_ATOM), 'click' => $click->reference],
            ['version' => 1, 'key' => 'subscribed:42', 'kind' => 'subscribed', 'at' => $now->format(DATE_ATOM), 'click' => 'zzzzzzzzzz'],
            $paid,
        ];
        $body = (string) json_encode($reports);

        $robot = $this->browser();
        foreach ([['', WebhookSignature::sign($body, [self::SECRET], time())], ['https://example.net', WebhookSignature::sign($body, [self::SECRET], time())],
            ['https://example.org', WebhookSignature::sign($body, ['someone-elses-secret-of-thirty-two-characters'], time())],
            ['https://example.org', WebhookSignature::sign($body, [self::SECRET], time() - 600)], ['https://example.org', '']] as [$from, $signature]) {
            $robot->request('POST', '/webhooks/marketing', server: ['HTTP_X_CATTOPHP_FROM' => $from, 'HTTP_X_CATTOPHP_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], content: $body);
            self::assertSame([401, ''], [$robot->getResponse()->getStatusCode(), $robot->getResponse()->getContent()], 'Bare: ' . $from);
        }
        self::assertSame('message: its signature did not match.', $this->sites()->get('example-shop')?->lastRefused);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_marketing_reports_in'));

        $robot->request('POST', '/webhooks/marketing', server: ['HTTP_X_CATTOPHP_FROM' => 'https://example.org', 'HTTP_X_CATTOPHP_SIGNATURE' => WebhookSignature::sign($body, [self::SECRET], time()),
            'CONTENT_TYPE' => 'application/json'], content: $body);
        self::assertSame([200, '{"recorded":2}'], [$robot->getResponse()->getStatusCode(), $robot->getResponse()->getContent()]);
        self::assertNotNull($robot->getResponse()->headers->get('Date'));
        self::assertSame([['registered:41', 'recorded'], ['sale_paid:CL-00010482', 'recorded'], ['subscribed:42', 'unknown_click']],
            array_map(static fn (array $row): array => array_values($row), $this->connection->fetchAllAssociative('SELECT key, result FROM cattophp_marketing_reports_in ORDER BY key')));
        $events = $this->container()->get(Events::class);
        self::assertInstanceOf(Events::class, $events);
        $registered = $events->byKey('site:example-shop:registered:41') ?? self::fail();
        self::assertSame([Events::REGISTERED, $click->visitorId, $click->campaignId, $click->publicationId, $click->id, 'example-shop', 'list', 'news', ['offer' => 'News'], null],
            [$registered->kind, $registered->visitorId, $registered->campaignId, $registered->publicationId, $registered->touchId, $registered->originSite, $registered->offerType,
                $registered->offerReference, $registered->detail, $registered->account]);
        $sales = $this->container()->get(Sales::class);
        self::assertInstanceOf(Sales::class, $sales);
        $sale = $sales->get('CL-00010482', 'example-shop') ?? self::fail('The sale is kept as sold on the other site.');
        self::assertSame(['paid', 150000, 'example-shop', $click->id, $click->id, 'site', $click->campaignId], [$sale->state, $sale->paidMinor, $sale->originSite, $sale->firstTouchId,
            $sale->lastTouchId, $sale->lastBy, $sale->lastCampaignId]);
        self::assertNull($sales->get('CL-00010482'), 'Never one of this site\'s own sales.');

        $robot->request('POST', '/webhooks/marketing', server: ['HTTP_X_CATTOPHP_FROM' => 'https://example.org', 'HTTP_X_CATTOPHP_SIGNATURE' => WebhookSignature::sign($body, [self::SECRET], time()),
            'CONTENT_TYPE' => 'application/json'], content: $body);
        self::assertSame([200, '{"recorded":0}'], [$robot->getResponse()->getStatusCode(), $robot->getResponse()->getContent()], 'A repeat records nothing.');
        $robot->request('POST', '/webhooks/marketing', server: ['HTTP_X_CATTOPHP_FROM' => 'https://example.org', 'HTTP_X_CATTOPHP_SIGNATURE' => WebhookSignature::sign('{"a":1}', [self::SECRET], time())],
            content: '{"a":1}');
        self::assertSame([401, ''], [$robot->getResponse()->getStatusCode(), $robot->getResponse()->getContent()], 'Signed, but no list of reports.');

        $robot->request('GET', '/webhooks/marketing/check', server: ['HTTP_X_CATTOPHP_FROM' => 'https://example.org/', 'HTTP_X_CATTOPHP_SIGNATURE' => WebhookSignature::sign('', [self::SECRET], time())]);
        self::assertSame([200, '{"name":"Example Site"}'], [$robot->getResponse()->getStatusCode(), $robot->getResponse()->getContent()]);
        $robot->request('GET', '/webhooks/marketing/check', server: ['HTTP_X_CATTOPHP_FROM' => 'https://example.org']);
        self::assertSame([401, ''], [$robot->getResponse()->getStatusCode(), $robot->getResponse()->getContent()]);
    }

    public function testTheCardAddsChecksChangesAndRemovesASiteItsSecretShownOnlyAsAsterisks(): void
    {
        $owner = $this->signedIn('owner@example.com');
        $page = $owner->request('GET', '/marketing/settings');
        self::assertCount(1, $page->filter('section#connected-sites'), 'One card on the one settings page.');
        self::assertSame('Connected sites', trim($page->filter('#connected-sites-heading')->text()));
        self::assertNotNull($page->filter('#site-add')->attr('open'), 'With no site yet, the form to connect one is open.');
        self::assertSame('', $page->filter('#site-add-secret')->attr('value'));
        $page = $owner->click($page->filter('#site-add a')->link());
        $made = (string) $page->filter('#site-add-secret')->attr('value');
        self::assertMatchesRegularExpression(Sites::SECRET, $made, 'Make a secret fills the field, to copy.');
        self::assertCount(1, $page->filter('#site-add button[data-cl-copy]'));
        self::assertStringContainsString('shown this once', $page->filter('#site-add-secret-help')->text());

        $owner->submit($page->filter('#site-add form')->form(), ['name' => 'Example Shop', 'address' => 'https://example.org', 'secret' => 'too short']);
        self::assertSame('/marketing/settings#site-add', $owner->getResponse()->headers->get('Location'));
        $page = $owner->followRedirect();
        self::assertStringContainsString('32 to 200 characters', $page->filter('#site-add-secret-error')->text());
        self::assertSame(['Example Shop', 'https://example.org', ''], [$page->filter('#site-add-name')->attr('value'), $page->filter('#site-add-address')->attr('value'),
            $page->filter('#site-add-secret')->attr('value')], 'Back as typed, the secret never kept.');
        $owner->submit($page->filter('#site-add form')->form(), ['name' => 'Example Shop', 'address' => 'https://example.org', 'secret' => $made]);
        self::assertSame('/marketing/settings#site-example-shop', $owner->getResponse()->headers->get('Location'));
        $page = $owner->followRedirect();
        self::assertStringContainsString('Example Shop is added.', $page->filter('.cl-flash-stack')->text());
        $card = $page->filter('#site-example-shop');
        self::assertStringContainsString('Not checked yet', $card->filter('h3')->text());
        self::assertStringContainsString('Shared secret************', $card->filter('dl')->text());
        self::assertStringNotContainsString($made, $owner->getInternalResponse()->getContent(), 'The secret is never printed back.');
        self::assertStringNotContainsString('v1:', $owner->getInternalResponse()->getContent());
        self::assertSame('Check the connection', trim($card->filter('form[action$="/check"] button')->text()));

        $responses = $this->responses();
        $responses->answer = static fn (): MockResponse => new MockResponse('{"name":"Example Shop"}', ['response_headers' => ['Date' => gmdate('D, d M Y H:i:s \G\M\T')]]);
        $this->press($owner, $page, '/marketing/settings/sites/example-shop/check');
        $page = $owner->followRedirect();
        self::assertStringContainsString('Example Shop is connected.', $page->filter('.cl-flash-stack')->text());
        $card = $page->filter('#site-example-shop');
        self::assertStringContainsString('Connected', $card->filter('h3 .cl-ui-badge')->text());
        self::assertSame(['Check again', 'Drop connection'], array_map('trim', $card->filter('.cl-ui-action-group button')->each(static fn (Crawler $button): string => $button->text())));
        self::assertStringContainsString('Less than a minute apart', $card->filter('dl')->text());
        self::assertSame(['GET', 'https://example.org/webhooks/marketing/check'], [$responses->requests[0]['method'], $responses->requests[0]['url']]);

        $responses->answer = static fn (): MockResponse => new MockResponse('', ['http_code' => 401]);
        $this->press($owner, $page, '/marketing/settings/sites/example-shop/check');
        $page = $owner->followRedirect();
        self::assertStringContainsString('Example Shop is not connected: the other site did not accept this site\'s signature.', $page->filter('.cl-flash-stack')->text());
        self::assertStringContainsString('Not connected', $page->filter('#site-example-shop h3')->text());
        self::assertStringContainsString('Check that both have the same shared secret.', $page->filter('#site-example-shop')->text());

        $page = $owner->request('GET', '/marketing/settings?make_secret=example-shop');
        self::assertNotNull($page->filter('#site-example-shop-secret')->attr('open'));
        $new = (string) $page->filter('#site-example-shop-secret-value')->attr('value');
        self::assertMatchesRegularExpression(Sites::SECRET, $new);
        $owner->submit($page->filter('#site-example-shop-secret form')->form());
        $page = $owner->followRedirect();
        self::assertStringContainsString('Example Shop has its new shared secret.', $page->filter('.cl-flash-stack')->text());
        self::assertStringContainsString('the previous one is still accepted', $page->filter('#site-example-shop dl')->text());
        $owner->submit($page->filter('#site-example-shop-secret form')->form(), ['secret' => 'short']);
        self::assertSame('/marketing/settings#site-example-shop-secret', $owner->getResponse()->headers->get('Location'));
        $page = $owner->followRedirect();
        self::assertNotNull($page->filter('#site-example-shop-secret')->attr('open'), 'Open again, with why.');
        self::assertStringContainsString('32 to 200 characters', $page->filter('#site-example-shop-secret-value-error')->text());

        $owner->submit($page->filter('#site-example-shop-change form')->form(), ['name' => 'Example Books', 'address' => 'https://books.example.org']);
        $page = $owner->followRedirect();
        self::assertStringContainsString('Example Books is saved.', $page->filter('.cl-flash-stack')->text());
        self::assertStringContainsString('https://books.example.org', $page->filter('#site-example-shop dl')->text());

        $site = $this->sites()->get('example-shop') ?? self::fail();
        $this->reports()->queue($site, 'sale_paid:CL-1', 'sale_paid', ['key' => 'sale_paid:CL-1'], new DateTimeImmutable());
        $this->connection->executeStatement('UPDATE cattophp_marketing_reports_out SET gave_up_at = now()');
        $page = $owner->request('GET', '/marketing/settings');
        self::assertStringContainsString('1 report could not be delivered.', $page->filter('#site-example-shop')->text());
        $this->press($owner, $page, '/marketing/settings/sites/example-shop/send');
        self::assertStringContainsString('1 report to Example Books will be sent again within a minute.', $owner->followRedirect()->filter('.cl-flash-stack')->text());

        $page = $owner->request('GET', '/marketing/settings');
        self::assertStringContainsString('1 report to it is not delivered yet.', $page->filter('#site-example-shop')->text());
        $owner->submit($page->filter('form[action="/marketing/settings/sites/example-shop/remove"]')->first()->form());
        self::assertStringContainsString('Example Books has 1 report not delivered yet, so it was not removed.', $owner->followRedirect()->filter('.cl-flash-stack')->text());
        $page = $owner->request('GET', '/marketing/settings');
        $owner->submit($page->filter('form[action="/marketing/settings/sites/example-shop/remove"]')->last()->form());
        self::assertSame('/marketing/settings#connected-sites', $owner->getResponse()->headers->get('Location'));
        $page = $owner->followRedirect();
        self::assertStringContainsString('Example Books is no longer connected, and 1 report to it was dropped. Its touches and sales keep its name.', $page->filter('.cl-flash-stack')->text());
        self::assertCount(0, $page->filter('#site-example-shop'));

        $links = $this->container()->get(MarketingSubjectLinks::class);
        self::assertInstanceOf(MarketingSubjectLinks::class, $links);
        self::assertSame('/marketing/settings#connected-sites', $links->href('marketing_site', 'example-shop'));
        $log = $owner->request('GET', '/admin/activity?action=marketing.site.removed');
        self::assertCount(1, $log->filter('a[href="/marketing/settings#connected-sites"]'), 'The activity log links the card.');
        self::assertSame(1, $this->logged('marketing.site.added'));
        self::assertSame([1, 1, 2, 1], [$this->logged('marketing.site.secret_changed'), $this->logged('marketing.site.changed'), $this->logged('marketing.site.checked'), $this->logged('marketing.site.removed')]);
    }

    public function testOnlyThoseWhoMayManageTheSettingsSeeTheCardOrChangeASite(): void
    {
        $this->sites()->add('Example Shop', 'https://example.org', self::SECRET);
        $token = $this->token($this->signedIn('owner@example.com')->request('GET', '/marketing/settings'));
        $roles = $this->container()->get(Roles::class);
        self::assertInstanceOf(Roles::class, $roles);
        $roles->save('WATCHER', 'Marketing helper', '', [MarketingPermissions::VIEW, MarketingPermissions::MANAGE]);
        $roles->grant('2', 'WATCHER');
        $anna = $this->signedIn('anna@example.com');
        $anna->request('GET', '/marketing/settings');
        self::assertSame(403, $anna->getResponse()->getStatusCode());
        foreach (['/marketing/settings/sites', '/marketing/settings/sites/example-shop', '/marketing/settings/sites/example-shop/check', '/marketing/settings/sites/example-shop/secret',
            '/marketing/settings/sites/example-shop/send', '/marketing/settings/sites/example-shop/remove'] as $action) {
            $anna->request('POST', $action, ['_token' => $token, 'name' => 'Taken over', 'address' => 'https://example.net', 'secret' => self::SECRET . '-taken', 'drop' => 'yes']);
            self::assertSame(403, $anna->getResponse()->getStatusCode(), $action);
        }
        self::assertSame(['Example Shop', 'https://example.org', [self::SECRET]], [$this->sites()->get('example-shop')?->name, $this->sites()->get('example-shop')?->address,
            $this->sites()->secrets($this->sites()->get('example-shop') ?? self::fail())]);
        self::assertSame([], $this->responses()->requests, 'Nothing was sent.');
        $stranger = $this->browser();
        $stranger->request('POST', '/marketing/settings/sites/example-shop/remove');
        self::assertSame(303, $stranger->getResponse()->getStatusCode(), 'A stranger is sent to sign in.');
    }

    /**
     * An address with a signed arrival from https://example.org (or the site the values name), as its tracking link gives it.
     *
     * @param array<string,mixed> $values
     */
    private function arrival(array $values, int $at, string $path = '/open/form', string $secret = self::SECRET): string
    {
        $payload = SiteForward::payload($values + ['v' => 1, 'site' => 'https://example.org', 'campaign' => 'law-nov26', 'name' => 'Law, November', 'publication' => 'em1', 'channel' => 'email']);

        return $path . (str_contains($path, '?') ? '&' : '?') . http_build_query([SiteForward::FROM => $payload, SiteForward::SIGNATURE => WebhookSignature::sign($payload, [$secret], $at)]);
    }

    /** The last answer's Location, read afresh (a browser's answer changes with each request). */
    private static function location(KernelBrowser $browser): ?string
    {
        return $browser->getResponse()->headers->get('Location');
    }

    private function campaign(string $code, string $name, string $destination): void
    {
        $this->campaigns()->create(new CampaignInput($name, $code, destination: $destination));
        $this->campaigns()->activate($code);
    }

    private function logged(string $action): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_activity WHERE action = :action', ['action' => $action]);
    }

    private function press(KernelBrowser $browser, Crawler $page, string $action): void
    {
        $button = $page->filter('form[action="' . $action . '"] button');
        self::assertGreaterThan(0, $button->count(), 'A form posts to ' . $action . '.');
        $browser->submit($button->first()->form());
    }

    private function token(Crawler $page): string
    {
        return (string) $page->filter('input[name="_token"]')->first()->attr('value');
    }

    private function sites(): Sites
    {
        $sites = $this->container()->get(Sites::class);
        self::assertInstanceOf(Sites::class, $sites);

        return $sites;
    }

    private function reports(): SiteReports
    {
        $reports = $this->container()->get(SiteReports::class);
        self::assertInstanceOf(SiteReports::class, $reports);

        return $reports;
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

    private function touches(): Touches
    {
        $touches = $this->container()->get(Touches::class);
        self::assertInstanceOf(Touches::class, $touches);

        return $touches;
    }

    private function responses(): MarketingSitesResponses
    {
        $responses = $this->container()->get(MarketingSitesResponses::class);
        self::assertInstanceOf(MarketingSitesResponses::class, $responses);

        return $responses;
    }

    private function logger(): RecordingLogger
    {
        $logger = $this->kernel->getContainer()->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);

        return $logger;
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
        $browser->setServerParameter('HTTP_USER_AGENT', self::PERSON);
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
}
