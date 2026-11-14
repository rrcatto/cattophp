<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\SignIn;

use CattoPHP\Http\LiveRefresh;
use CattoPHP\Sessions\MemorySessionStore;
use CattoPHP\SignIn\SignInAccount;
use CattoPHP\SignIn\SignInFlow;
use CattoPHP\SignIn\SignInLength;
use CattoPHP\SignIn\SignInLengthStorageFactory;
use CattoPHP\SignIn\SignInSessions;
use CattoPHP\Tests\Support\Foundations;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorageFactory;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageFactoryInterface;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * How long a sign-in lasts without a visit governs the whole session: the sign-in's end, moved on by each visit, a
 * page's own refresh included; the stored session's and the cookie's lifetimes; a shorter setting at once, a longer one
 * from the next visit.
 */
final class SignInLengthTest extends TestCase
{
    public function testASignInEndsAfterTheLengthWithoutAVisitAShorterSettingAtOnceALongerFromTheNextVisit(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $flow = new SignInFlow();
        self::assertNull(SignInFlow::endsAt($session, 86400), 'Nobody signed in.');

        $flow->signedIn($session, 1_000_000, 1_000_000 + 86400);
        self::assertSame(1_000_000 + 86400, SignInFlow::endsAt($session, 86400));
        $flow->visited($session, 1_050_000, 1_050_000 + 86400);
        self::assertSame(1_050_000 + 86400, SignInFlow::endsAt($session, 86400), 'A visit moves it on.');
        self::assertSame(1_050_000 + 86400, SignInFlow::endsAt($session, 30 * 86400), 'A longer setting waits for the next visit.');
        self::assertSame(1_050_000 + 3600, SignInFlow::endsAt($session, 3600), 'A shorter setting applies at once, from the last visit.');

        // A sign-in kept before visits were: from signing in, as the setting says now, as it always was.
        $session->remove(SignInFlow::SIGNED_IN_UNTIL);
        $session->remove(SignInFlow::LAST_VISIT);
        self::assertSame(1_000_000 + 7200, SignInFlow::endsAt($session, 7200));
    }

    public function testEveryRequestIsAVisitARefreshTooAndTheEndMovesOnlyWhenItWouldMoveEnough(): void
    {
        $site = new Foundations(['AUTH_SESSION_TTL' => '1d']);
        $length = new SignInLength($site->settings, $site->clock);
        self::assertTrue($length->countsAsVisit(Request::create('/notes')));
        self::assertTrue($length->countsAsVisit(Request::create('/notes', 'POST')));
        self::assertTrue($length->countsAsVisit(Request::create('/notes', server: ['HTTP_HX_REQUEST' => 'true'])), 'A part of a page the person asked for.');
        $marked = Request::create('/admin/activity', server: ['HTTP_HX_REQUEST' => 'true', 'HTTP_X_LIVE_REFRESH' => '1']);
        LiveRefresh::notAVisit($marked);
        self::assertTrue($length->countsAsVisit($marked), 'A live page\'s own refresh: a page left open keeps the person signed in.');

        self::assertSame(864, SignInLength::moveEvery(86400), 'A hundredth of a day.');
        self::assertSame(3600, SignInLength::moveEvery(30 * 86400), 'At most an hour.');
        self::assertSame(3, SignInLength::moveEvery(300));
    }

    public function testTheStoredSessionLivesAsLongAsASignInAndTheCookieUntilOneEndsWithTheBrowser(): void
    {
        $site = new Foundations(['AUTH_SESSION_TTL' => '30d']);
        $length = new SignInLength($site->settings, $site->clock);
        self::assertSame(30 * 86400, $length->seconds());
        self::assertSame(100 + 30 * 86400, $length->endFrom(100));

        $native = new class extends NativeSessionStorage {
            /** @var array<string,mixed> */
            public array $given = [];

            public function __construct()
            {
            }

            /** @param array<string,mixed> $options */
            public function setOptions(array $options): void
            {
                $this->given = $options + $this->given;
            }
        };
        $inner = new class ($native) implements SessionStorageFactoryInterface {
            public function __construct(private readonly SessionStorageInterface $storage)
            {
            }

            public function createStorage(?Request $request): SessionStorageInterface
            {
                return $this->storage;
            }
        };
        (new SignInLengthStorageFactory($inner, $length))->createStorage(null);
        self::assertSame(['gc_maxlifetime' => 30 * 86400, 'cookie_lifetime' => 0], $native->given, 'Over whatever framework.yaml gave: the stored session a sign-in\'s length, the cookie ending with the browser until a sign-in gives it one.');

        $mock = new MockArraySessionStorage();
        $inner = new class ($mock) implements SessionStorageFactoryInterface {
            public function __construct(private readonly SessionStorageInterface $storage)
            {
            }

            public function createStorage(?Request $request): SessionStorageInterface
            {
                return $this->storage;
            }
        };
        self::assertSame($mock, (new SignInLengthStorageFactory($inner, $length))->createStorage(null), 'Storage that is not PHP\'s own is left as it is.');
    }

    /** PHP's own session settings, as a request's storage leaves them: over what framework.yaml gives. */
    #[RunInSeparateProcess]
    public function testPhpsSessionIsGivenTheSignInsLengthOverFrameworkYaml(): void
    {
        if (headers_sent()) {
            self::markTestSkipped('PHP takes no session settings once output has begun.');
        }
        $site = new Foundations(['AUTH_SESSION_TTL' => '30d']);
        // As framework.yaml's session: gc_maxlifetime 1440 and cookie_lifetime 3600 would give it.
        $configured = new NativeSessionStorageFactory(['gc_maxlifetime' => 1440, 'cookie_lifetime' => 3600, 'cookie_httponly' => true, 'cookie_samesite' => 'lax']);
        (new SignInLengthStorageFactory($configured, new SignInLength($site->settings, $site->clock)))->createStorage(null);

        // The stored session lasts a sign-in's length; the cookie, until a sign-in gives it one, ends with the browser.
        self::assertSame([(string) (30 * 86400), '0', '1', 'lax'],
            [ini_get('session.gc_maxlifetime'), ini_get('session.cookie_lifetime'), ini_get('session.cookie_httponly'), ini_get('session.cookie_samesite')]);
    }

    public function testAVisitMovesTheEndTheKeptSignInAndTheCookieARefreshToo(): void
    {
        $site = new Foundations(['AUTH_SESSION_TTL' => '30d']);
        $sessions = new SignInSessions(new MemorySessionStore(), $site->keyedHash(), $site->settings, $site->activity, $site->clock);
        $length = new SignInLength($site->settings, $site->clock, $sessions, ['cookie_httponly' => true, 'cookie_samesite' => 'lax', 'cookie_secure' => 'auto', 'cookie_path' => '/']);
        $kernel = $this->createStub(HttpKernelInterface::class);
        $session = new Session(new MockArraySessionStorage('EXAMPLESESSID'));
        $session->start();
        $signedInAt = $site->clock->now()->getTimestamp();
        (new SignInFlow())->signedIn($session, $signedInAt, $signedInAt + 30 * 86400);
        $sessions->begin($session, new SignInAccount('7', 'ada@example.org', 'Ada Example'));
        $answer = function (Request $request) use ($kernel, $length, $session): Response {
            $request->setSession($session);
            $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response());
            $length->visit($event);
            $length->keepCookie($event);

            return $event->getResponse();
        };

        // Five days on, a page the person opens: the end, the kept sign-in and the cookie all move on 30 days from now.
        $site->clock->modify('+5 days');
        $now = $site->clock->now()->getTimestamp();
        $response = $answer(Request::create('https://example.com/notes'));
        self::assertSame($now + 30 * 86400, SignInFlow::endsAt($session, 30 * 86400));
        $kept = $sessions->current($session);
        self::assertNotNull($kept);
        self::assertSame($now + 30 * 86400, $kept->expiresAt->getTimestamp(), 'The kept sign-in moves with it.');
        self::assertSame($now, $kept->lastSeenAt->getTimestamp());
        $cookie = $response->headers->getCookies()[0] ?? null;
        self::assertNotNull($cookie, 'The cookie is sent again with the later end.');
        self::assertSame(['EXAMPLESESSID', $session->getId(), $now + 30 * 86400, '/', true, 'lax'],
            [$cookie->getName(), $cookie->getValue(), $cookie->getExpiresTime(), $cookie->getPath(), $cookie->isHttpOnly(), $cookie->getSameSite()], 'Its flags as Symfony makes them.');

        // Ten minutes later, another page: the end would move by less than an hour, so nothing is written or sent.
        $site->clock->modify('+10 minutes');
        self::assertSame([], $answer(Request::create('https://example.com/notes/2'))->headers->getCookies());
        self::assertSame($now + 30 * 86400, SignInFlow::endsAt($session, 30 * 86400));

        // A day on, the page's own refresh: a visit too, moving everything on.
        $site->clock->modify('+1 day');
        $now = $site->clock->now()->getTimestamp();
        $refresh = Request::create('https://example.com/admin/activity', server: ['HTTP_HX_REQUEST' => 'true', 'HTTP_X_LIVE_REFRESH' => '1']);
        self::assertSame($now + 30 * 86400, ($answer($refresh)->headers->getCookies()[0] ?? null)?->getExpiresTime());
        self::assertSame($now + 30 * 86400, SignInFlow::endsAt($session, 30 * 86400));
        self::assertSame($now + 30 * 86400, $sessions->current($session)?->expiresAt->getTimestamp());

        // Fifteen seconds later, the next refresh, with a new session id: too soon to move the end, so Symfony's cookie
        // is given the end as it is, never later.
        $site->clock->modify('+15 seconds');
        $refresh = Request::create('https://example.com/admin/activity', server: ['HTTP_HX_REQUEST' => 'true', 'HTTP_X_LIVE_REFRESH' => '1']);
        $refresh->setSession($session);
        $response = new Response();
        $response->headers->setCookie(Cookie::create('EXAMPLESESSID', 'abc123', 0, '/', null, true, true, false, Cookie::SAMESITE_LAX));
        $response->headers->setCookie(Cookie::create('other', 'kept', 0));
        $event = new ResponseEvent($kernel, $refresh, HttpKernelInterface::MAIN_REQUEST, $response);
        $length->visit($event);
        $length->keepCookie($event);
        $cookies = [];
        foreach ($response->headers->getCookies() as $sent) {
            $cookies[$sent->getName()] = $sent;
        }
        self::assertSame($now + 30 * 86400, $cookies['EXAMPLESESSID']->getExpiresTime());
        self::assertSame(['abc123', '/', null, true, true, 'lax'], [$cookies['EXAMPLESESSID']->getValue(), $cookies['EXAMPLESESSID']->getPath(), $cookies['EXAMPLESESSID']->getDomain(),
            $cookies['EXAMPLESESSID']->isSecure(), $cookies['EXAMPLESESSID']->isHttpOnly(), $cookies['EXAMPLESESSID']->getSameSite()], 'Every flag as Symfony made it.');
        self::assertSame(0, $cookies['other']->getExpiresTime(), 'Another cookie is not touched.');

        // Shortened to a day, two days after the last visit: the sign-in has ended, and the page says so.
        $site->clock->modify('+2 days');
        $site->settings->set('AUTH_SESSION_TTL', '1d');
        self::assertSame($now + 86400, SignInFlow::endsAt($session, 86400));
        self::assertFalse($sessions->holds($session, '7'));
        self::assertSame([], $sessions->of('7'));

        // Not signed in yet: the cookie Symfony sends has no expiry, ending when the browser is closed, its flags kept.
        $site->settings->set('AUTH_SESSION_TTL', '30d');
        $stranger = new Session(new MockArraySessionStorage('EXAMPLESESSID'));
        $stranger->start();
        $request = Request::create('https://example.com/sign-in');
        $request->setSession($stranger);
        $response = new Response();
        $response->headers->setCookie(Cookie::create('EXAMPLESESSID', 'def456', $site->clock->now()->getTimestamp() + 3600, '/', null, true, true, false, Cookie::SAMESITE_LAX));
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $length->visit($event);
        $length->keepCookie($event);
        $anonymous = $response->headers->getCookies()[0];
        self::assertSame([0, 'def456', '/', true, true, 'lax'], [$anonymous->getExpiresTime(), $anonymous->getValue(), $anonymous->getPath(), $anonymous->isSecure(), $anonymous->isHttpOnly(), $anonymous->getSameSite()]);

        // Signing out clears the cookie: left cleared.
        $response = new Response();
        $response->headers->clearCookie('EXAMPLESESSID');
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $length->keepCookie($event);
        self::assertTrue($response->headers->getCookies()[0]->isCleared());
    }
}
