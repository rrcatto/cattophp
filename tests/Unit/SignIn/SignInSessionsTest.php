<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\SignIn;

use CattoPHP\Sessions\MemorySessionStore;
use CattoPHP\SignIn\SignInAccount;
use CattoPHP\SignIn\SignInSessions;
use CattoPHP\Tests\Support\Foundations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/** The sign-ins a person can see and end: kept as each finishes, checked, seen, ended one at a time or all but this one. */
final class SignInSessionsTest extends TestCase
{
    public function testASignInIsKeptByAHashOfItsTokenAndHoldsUntilItIsEndedOrRunsOut(): void
    {
        $site = new Foundations(['AUTH_SESSION_TTL' => '12h']);
        $store = new MemorySessionStore();
        $sessions = new SignInSessions($store, $site->keyedHash(), $site->settings, $site->activity, $site->clock);
        $here = new Session(new MockArraySessionStorage());
        $account = new SignInAccount('7', 'ada@example.org', 'Ada Example');

        $kept = $sessions->begin($here, $account, Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox/144.0']));
        $token = $here->get(SignInSessions::TOKEN);
        self::assertIsString($token);
        self::assertNotNull($store->active(hash('sha256', $token), $site->clock->now()), 'Kept by the hash of the token the session holds.');
        self::assertSame(['7', 'ada@example.org', 'Mozilla/5.0 Firefox/144.0'], [$kept->accountId, $kept->email, $kept->userAgent]);
        self::assertSame($site->keyedHash()->of('sign_in_from', '203.0.113.9'), $kept->requestedFrom, 'Where from, as a fingerprint, never the address.');
        self::assertSame($site->clock->now()->modify('+12 hours')->getTimestamp(), $kept->expiresAt->getTimestamp());
        self::assertTrue($sessions->holds($here, '7'));
        self::assertFalse($sessions->holds($here, '8'), 'Never another account\'s.');

        $site->clock->modify('+10 minutes');
        self::assertTrue($sessions->holds($here, '7'));
        $seen = $sessions->current($here);
        self::assertNotNull($seen);
        self::assertSame($site->clock->now()->getTimestamp(), $seen->lastSeenAt->getTimestamp(), 'Seen, every few minutes at most.');
        self::assertSame($kept->expiresAt->getTimestamp(), $seen->expiresAt->getTimestamp(), 'It still runs out when it began to.');
        $site->clock->modify('+12 hours');
        self::assertFalse($sessions->holds($here, '7'), 'Run out.');
    }

    public function testOneIsEndedOrEveryOneButThisAndSigningOutEndsThisOne(): void
    {
        $site = new Foundations();
        $sessions = new SignInSessions(new MemorySessionStore(), $site->keyedHash(), $site->settings, $site->activity, $site->clock);
        $account = new SignInAccount('7', 'ada@example.org', 'Ada Example');
        $here = new Session(new MockArraySessionStorage());
        $there = new Session(new MockArraySessionStorage());
        $elsewhere = new Session(new MockArraySessionStorage());
        $sessions->begin($here, $account);
        $other = $sessions->begin($there, $account);
        $sessions->begin($elsewhere, $account);
        self::assertCount(3, $sessions->of('7'));

        self::assertFalse($sessions->end('8', $other->publicId), 'Never another account\'s.');
        self::assertTrue($sessions->end('7', $other->publicId));
        self::assertFalse($sessions->holds($there, '7'), 'That browser is signed out at its next page.');
        self::assertFalse($sessions->end('7', $other->publicId), 'Once.');
        $sessions->begin($there, $account);
        self::assertSame(2, $sessions->endOthers('7', $here));
        self::assertTrue($sessions->holds($here, '7'), 'This one carries on.');
        self::assertFalse($sessions->holds($elsewhere, '7'));
        self::assertSame(['sign_in.session_ended', 'sign_in.sessions_ended'], array_map(static fn ($entry): string => $entry->action, array_reverse($site->activity->recent(5))));
        self::assertSame(['ended' => 2], $site->activity->recent(1)[0]->details);

        $sessions->finish($here);
        self::assertFalse($sessions->holds($here, '7'));
        self::assertSame([], $sessions->of('7'));
        self::assertSame(0, $sessions->endOthers('7', $here), 'Nothing left to end.');
    }
}
