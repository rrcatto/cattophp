<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Http;

use CattoPHP\Http\BotCheck;
use CattoPHP\Http\ContentSecurityPolicy;
use CattoPHP\Http\ErrorPageListener;
use CattoPHP\Http\RequestReference;
use CattoPHP\Http\Throttle;
use CattoPHP\Http\TypedForm;
use CattoPHP\Http\TypedFormListener;
use CattoPHP\Foundation\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class HttpTest extends TestCase
{
    private const CHROME = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36';

    public function testThePolicyAllowsOnlyTheSiteItselfAndThePagesNonce(): void
    {
        $requests = new RequestStack();
        $request = Request::create('https://example.com/');
        $requests->push($request);
        $policy = new ContentSecurityPolicy($requests);
        $nonce = $policy->nonce();

        $header = $policy->header($request);
        self::assertStringContainsString("default-src 'none'", $header);
        self::assertStringContainsString("script-src 'self' 'nonce-" . $nonce . "'", $header);
        self::assertStringContainsString("style-src 'self';", $header);
        self::assertStringNotContainsString('unsafe-inline', $header);
        self::assertStringNotContainsString('unsafe-eval', $header);
        self::assertStringEndsWith('upgrade-insecure-requests', $header);
        self::assertSame($nonce, $policy->nonce(), 'one nonce for the whole page');
    }

    public function testAuthoredHtmlMayHaveItsStylesButNeverItsScripts(): void
    {
        $request = Request::create('http://example.com/');
        $request->attributes->set('_csp', ContentSecurityPolicy::AUTHORED);
        $header = (new ContentSecurityPolicy(new RequestStack(), ['img-src' => "'self'"]))->header($request);

        self::assertStringContainsString("style-src 'self' 'unsafe-inline'", $header);
        self::assertStringContainsString("img-src 'self' https:", $header);
        self::assertStringContainsString("script-src 'self';", $header);
        self::assertStringNotContainsString('upgrade-insecure-requests', $header, 'only over HTTPS');
    }

    public function testAReferenceCanBeReadOut(): void
    {
        $request = Request::create('/');
        $reference = RequestReference::of($request);

        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $reference);
        self::assertSame($reference, RequestReference::of($request));
    }

    /** @return iterable<string,array{0:string,1:array<string,string>,2:string,3:?string}> */
    public static function forms(): iterable
    {
        yield 'a person' => [self::CHROME, [], '203.0.113.5', null];
        yield 'the hidden field filled in' => [self::CHROME, ['website' => 'http://spam.example'], '203.0.113.5', 'trap'];
        yield 'no browser' => ['', [], '203.0.113.5', 'no_browser'];
        yield 'a script' => ['python-requests/2.32.3', [], '203.0.113.5', 'script'];
        yield 'a headless browser' => ['Mozilla/5.0 HeadlessChrome/142.0.0.0', [], '203.0.113.5', 'script'];
        yield 'Internet Explorer' => ['Mozilla/5.0 (Windows NT 6.1; Trident/7.0; rv:11.0) like Gecko', [], '203.0.113.5', 'outdated'];
        yield 'the owner\'s own tools on a private address' => ['curl/8.5.0', ['website' => 'x'], '10.0.2.100', null];
        yield 'a test site on this machine' => ['', [], '127.0.0.1', null];
    }

    /** @param array<string,string> $posted */
    #[DataProvider('forms')]
    public function testABotsFormIsToldApartFromAPersons(string $agent, array $posted, string $address, ?string $signal): void
    {
        $request = Request::create('/sign-in', 'POST', $posted, server: ['REMOTE_ADDR' => $address, 'HTTP_USER_AGENT' => $agent]);
        if ($agent === '') {
            $request->headers->remove('User-Agent');
        }

        self::assertSame($signal, BotCheck::signal($request));
    }

    public function testAMarkedNetworkIsRefused(): void
    {
        $request = Request::create('/sign-in', 'POST', server: ['REMOTE_ADDR' => '203.0.113.5', 'HTTP_USER_AGENT' => self::CHROME, 'HTTP_X_SUSPECT_NETWORK' => '1']);

        self::assertSame('suspect_network', BotCheck::signal($request));
    }

    public function testARefusedFormComesBackAsTypedOnceAndOnlyToItsOwnForm(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $sent = Request::create('/roles/new', 'POST', ['name' => 'Editor', '_token' => 'secret-token', 'csrf' => 'another-token', 'password' => 'never', 'one_time_code' => '123456', 'permissions' => ['PAGE.EDIT', 'PAGE.VIEW'], 'code' => 'EDT-101']);
        $sent->setSession($session);
        TypedForm::keep($sent);

        $page = Request::create('/roles');
        $page->setSession($session);
        $typed = ['name' => 'Editor', 'permissions' => ['PAGE.EDIT', 'PAGE.VIEW'], 'code' => 'EDT-101'];
        self::assertSame($typed, TypedForm::peek($page, '/roles/new'), 'looking takes nothing; a field called code (a course code) is kept');
        self::assertSame([], TypedForm::take($page, '/somewhere-else'), 'another form on the page gets nothing');
        self::assertSame($typed, TypedForm::take($page, '/roles/new?step=2#form'), 'its own form gets them, on the same page');

        $later = Request::create('/roles');
        $later->setSession($session);
        self::assertSame([], TypedForm::take($later, '/roles/new'), 'it shows once');
    }

    public function testWhatTheNextPageDidNotUseIsForgottenOnceItIsSent(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $sent = Request::create('/roles/new', 'POST', ['name' => 'Editor']);
        $sent->setSession($session);
        TypedForm::keep($sent);
        $listener = new TypedFormListener();
        $kernel = $this->createStub(HttpKernelInterface::class);

        foreach ([
            'a redirect' => [Request::create('/roles'), new RedirectResponse('/roles')],
            'a part of a page' => [Request::create('/roles', server: ['HTTP_HX_REQUEST' => 'true']), new Response('<p>part</p>')],
            'a picture' => [Request::create('/logo.png'), new Response('png', 200, ['Content-Type' => 'image/png'])],
        ] as $what => [$request, $response]) {
            $request->setSession($session);
            $listener->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
            self::assertSame(['name' => 'Editor'], TypedForm::peek($request, '/roles/new'), $what . ' leaves them waiting');
        }
        $page = Request::create('/dashboard');
        $page->setSession($session);
        $listener->onResponse(new ResponseEvent($kernel, $page, HttpKernelInterface::MAIN_REQUEST, new Response('<p>a page without the form</p>')));
        $later = Request::create('/roles');
        $later->setSession($session);
        self::assertSame([], TypedForm::peek($later, '/roles/new'), 'a page has been drawn: forgotten');
    }

    #[RunInSeparateProcess]
    public function testAProjectRunningItsOwnPhpSessionKeepsThemThere(): void
    {
        ini_set('session.use_cookies', '0');
        ini_set('session.cache_limiter', '');
        ini_set('session.save_path', sys_get_temp_dir());
        session_start();
        $sent = Request::create('/roles/new', 'POST', ['name' => 'Editor']);
        self::assertFalse($sent->hasSession());
        TypedForm::keep($sent);
        self::assertSame(['action' => '/roles/new', 'values' => ['name' => 'Editor']], $_SESSION[TypedForm::SESSION]);

        self::assertSame(['name' => 'Editor'], TypedForm::take(Request::create('/roles'), '/roles/new'));
        self::assertArrayNotHasKey(TypedForm::SESSION, $_SESSION);
        session_destroy();
    }

    public function testTheThrottleCountsEachKeyAndRefusesWithoutCounting(): void
    {
        $throttle = new Throttle(new ArrayAdapter());
        $window = Duration::parse('15m');

        self::assertTrue($throttle->allow('test', ['a@example.com', '203.0.113.5'], 2, $window));
        self::assertTrue($throttle->allow('test', ['A@Example.com ', '203.0.113.6'], 2, $window), 'an address is counted however it is typed');
        self::assertFalse($throttle->allow('test', ['a@example.com', '203.0.113.7'], 2, $window));
        self::assertTrue($throttle->allow('test', ['b@example.com', '203.0.113.7'], 2, $window), 'the refused attempt counted nothing');
        self::assertTrue($throttle->allow('other', ['a@example.com'], 2, $window), 'each action is counted apart');
    }

    /** @return iterable<string,array{0:string,1:int,2:string}> */
    public static function messages(): iterable
    {
        yield 'no route' => ['No route found for "GET http://example.com/nowhere"', 404, 'The address may be mistyped, or the page has moved or been removed.'];
        yield 'wrong method' => ['No route found for "PUT http://example.com/": Method Not Allowed (Allow: GET)', 405, 'That address does not take this kind of request. Go back and use the page\'s own buttons and links.'];
        yield 'form token' => ['Invalid CSRF token.', 400, 'The form had expired, or was sent from a page opened long ago. Go back, reload the page and send it again.'];
        yield 'access denied' => ['Access Denied.', 403, 'You do not have permission to open this.'];
        yield 'an object not found' => ['"App\Entity\Order" object not found by "EntityValueResolver".', 404, 'The address may be mistyped, or the page has moved or been removed.'];
        yield 'a plain sentence of the site\'s own' => ['That course is not on sale.', 409, 'That course is not on sale.'];
        yield 'nothing' => ['', 400, 'The request could not be completed.'];
    }

    #[DataProvider('messages')]
    public function testAnErrorIsPutInPlainWords(string $message, int $status, string $plain): void
    {
        self::assertSame($plain, ErrorPageListener::plain($message, $status));
    }

    public function testAProjectDrawsTheErrorPageAndAFailingDrawerGivesPlainHtml(): void
    {
        $drawer = new class implements \CattoPHP\Http\ErrorPageDrawer {
            /** @var list<\CattoPHP\Http\ErrorPage> */
            public array $drawn = [];

            public bool $fails = false;

            public function draw(\CattoPHP\Http\ErrorPage $page, \Symfony\Component\HttpFoundation\Request $request): string
            {
                if ($this->fails) {
                    throw new \RuntimeException('The theme is broken.');
                }
                $this->drawn[] = $page;

                return '<p>Drawn: ' . $page->title . '</p>';
            }
        };
        $kernel = new class implements \Symfony\Component\HttpKernel\HttpKernelInterface {
            public function handle(\Symfony\Component\HttpFoundation\Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): \Symfony\Component\HttpFoundation\Response
            {
                return new \Symfony\Component\HttpFoundation\Response();
            }
        };
        $listener = new \CattoPHP\Http\ErrorPageListener($drawer, new \Psr\Log\NullLogger(), false, ['/api/']);
        $event = new \Symfony\Component\HttpKernel\Event\ExceptionEvent($kernel, \Symfony\Component\HttpFoundation\Request::create('/missing'), \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST,
            new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('No route found for "GET /missing"'));
        $listener->onException($event);
        self::assertSame(404, $event->getResponse()?->getStatusCode());
        self::assertSame('<p>Drawn: Not found</p>', $event->getResponse()->getContent());
        self::assertSame(['not_found', 'The address may be mistyped, or the page has moved or been removed.', '/missing'], [$drawer->drawn[0]->kind, $drawer->drawn[0]->message, $drawer->drawn[0]->retryUrl]);
        self::assertNotSame('', $drawer->drawn[0]->reference);

        $drawer->fails = true;
        $broken = new \Symfony\Component\HttpKernel\Event\ExceptionEvent($kernel, \Symfony\Component\HttpFoundation\Request::create('/oops'), \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST, new \LogicException('secret detail'));
        $listener->onException($broken);
        self::assertSame(500, $broken->getResponse()?->getStatusCode());
        self::assertStringContainsString('It is not something you did.', (string) $broken->getResponse()->getContent());
        self::assertStringNotContainsString('secret detail', (string) $broken->getResponse()->getContent());

        $api = new \Symfony\Component\HttpKernel\Event\ExceptionEvent($kernel, \Symfony\Component\HttpFoundation\Request::create('/api/v1/x'), \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST, new \LogicException('x'));
        $listener->onException($api);
        self::assertNull($api->getResponse(), 'the project answers its own');
    }

    public function testFramingFollowsThePolicyAndAResponsesOwnHeadersStay(): void
    {
        $kernel = new class implements \Symfony\Component\HttpKernel\HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): \Symfony\Component\HttpFoundation\Response
            {
                return new \Symfony\Component\HttpFoundation\Response();
            }
        };
        $headersFor = static function (array $changes, string $permissions, ?\Symfony\Component\HttpFoundation\Response $response = null) use ($kernel): \Symfony\Component\HttpFoundation\ResponseHeaderBag {
            $requests = new RequestStack();
            $request = Request::create('https://example.com/');
            $requests->push($request);
            $site = new \CattoPHP\Tests\Support\Foundations(['APP_SITE_MODE' => 'live']);
            $listener = new \CattoPHP\Http\SecurityHeadersListener(new ContentSecurityPolicy($requests, $changes), $site->siteMode, permissionsPolicy: $permissions);
            $event = new \Symfony\Component\HttpKernel\Event\ResponseEvent($kernel, $request, \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST, $response ?? new \Symfony\Component\HttpFoundation\Response());
            $listener->onResponse($event);

            return $event->getResponse()->headers;
        };
        self::assertSame('DENY', $headersFor([], \CattoPHP\Http\SecurityHeadersListener::PERMISSIONS_POLICY)->get('X-Frame-Options'));
        $own = $headersFor(['frame-ancestors' => "'self'"], 'microphone=(self)');
        self::assertSame('SAMEORIGIN', $own->get('X-Frame-Options'), 'a project showing its own pages in frames');
        self::assertSame('microphone=(self)', $own->get('Permissions-Policy'));
        self::assertNull($headersFor(['frame-ancestors' => 'https://partner.example.org'], '')->get('X-Frame-Options'), 'only frame-ancestors can say that');
        $kept = $headersFor([], \CattoPHP\Http\SecurityHeadersListener::PERMISSIONS_POLICY, new \Symfony\Component\HttpFoundation\Response('', 200, ['X-Frame-Options' => 'SAMEORIGIN', 'Content-Security-Policy' => "default-src 'none'"]));
        self::assertSame(['SAMEORIGIN', "default-src 'none'"], [$kept->get('X-Frame-Options'), $kept->get('Content-Security-Policy')], 'a response that says these for itself keeps them');
    }

    public function testAPageMayAllowOneMoreSourceForItselfOnly(): void
    {
        $requests = new RequestStack();
        $page = Request::create('https://example.com/verify');
        $requests->push($page);
        $policy = new ContentSecurityPolicy($requests, ['form-action' => "'self'", 'frame-src' => "'none'"]);
        $policy->allow('form-action', 'https:');
        $policy->allow('form-action', 'https:');
        $policy->allow('frame-src', 'https://video.example.org');
        $policy->allow('form-action', "https: 'unsafe-inline'");
        $policy->allow('script-src;', 'https:');

        $header = $policy->header($page);
        self::assertStringContainsString("form-action 'self' https:;", $header, 'once, beside what the policy says');
        self::assertStringContainsString('frame-src https://video.example.org;', $header, "'none' gives way to the one source");
        self::assertStringContainsString("script-src 'self';", $header, 'a source or directive that is not one is ignored');

        $next = Request::create('https://example.com/other');
        self::assertStringContainsString("form-action 'self';", $policy->header($next), 'another page keeps the policy as it is');
    }

    public function testAProgramDrawingALinksPreviewIsNotAPerson(): void
    {
        foreach (['WhatsApp/2.23.20.0 A' => 'Link preview: WhatsApp', 'WhatsApp/2.2412.54 i' => 'Link preview: WhatsApp', 'Iframely/1.3.1 (+https://iframely.com/docs/about)' => 'Link preview: Iframely',
            'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)' => null, 'TelegramBot (like TwitterBot)' => null, 'Twitterbot/1.0' => null] as $agent => $label) {
            $read = \CattoPHP\Http\VisitorAgent::read($agent);
            self::assertTrue($read->isAutomated(), $agent);
            if ($label !== null) self::assertSame($label, $read->label, $agent);
        }
        $phone = \CattoPHP\Http\VisitorAgent::read('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36 WhatsApp/2.24');
        self::assertFalse($phone->isAutomated(), "a person's browser that mentions WhatsApp later on is still a person's");
    }
}
