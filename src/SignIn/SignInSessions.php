<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use CattoPHP\Security\KeyedHash;
use CattoPHP\Sessions\SessionStore;
use CattoPHP\Sessions\SignInSession;
use CattoPHP\Settings\Settings;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * The sign-ins a person can see and end, for a project whose people sign in through CattoPHP's sign-in pages and which
 * switches it on (`cattophp.sign_in.sessions: true`). Each sign-in is kept among CattoPHP's sign-ins (Sessions\SessionStore,
 * the table cattophp_sessions with a database) by the hash of a token held in the visitor's session: who signed in, with
 * which address, in which browser, from where (a keyed fingerprint of the network address, never the address), when it
 * began, was last seen and runs out (AUTH_SESSION_TTL after it began). Every request checks it (SignInUserProvider), so a
 * sign-in ended here, or run out, signs that browser out at its next page. The account page lists them with End and End
 * all others.
 *
 * Off by default: a project that keeps its sign-ins itself through the session store (one with sign-in pages of its own)
 * leaves it off, so nothing is kept twice.
 */
final class SignInSessions
{
    /** Where the visitor's session holds the token its sign-in is kept by. */
    public const TOKEN = '_cattophp_sign_in_session';

    /** How seldom a sign-in's last-seen time is written, in seconds: often enough to say when, seldom enough to cost nothing. */
    public const SEEN_EVERY = 300;

    /** The longest browser description kept. */
    private const LONGEST_AGENT = 500;

    public function __construct(
        private readonly SessionStore $store,
        private readonly KeyedHash $hash,
        private readonly Settings $settings,
        private readonly ActivityLog $activity,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Keeps a new sign-in for the account in this browser's session: called once the person is signed in. */
    public function begin(SessionInterface $session, SignInAccount $account, ?Request $request = null): SignInSession
    {
        $now = $this->now();
        $token = bin2hex(random_bytes(32));
        $address = (string) $request?->getClientIp();
        $agent = mb_substr(trim((string) $request?->headers->get('User-Agent', '')), 0, self::LONGEST_AGENT);
        $kept = $this->store->start(self::hashOf($token), bin2hex(random_bytes(16)), $account->id, $account->email,
            $address === '' ? null : $this->hash->of('sign_in_from', $address), $agent === '' ? null : $agent, $now,
            $this->settings->duration('AUTH_SESSION_TTL')->after($now));
        $session->set(self::TOKEN, $token);

        return $kept;
    }

    /**
     * Whether this browser's sign-in is still going for the account: kept, not ended, not run out. Notes that it was seen,
     * at most every SEEN_EVERY seconds.
     */
    public function holds(SessionInterface $session, string $accountId): bool
    {
        $current = $this->current($session);
        if ($current === null || $current->accountId !== $accountId) {
            return false;
        }
        $now = $this->now();
        if ($now->getTimestamp() - $current->lastSeenAt->getTimestamp() >= self::SEEN_EVERY) {
            $this->store->touch($current->id, $now, $current->expiresAt);
        }

        return true;
    }

    /** This browser's sign-in, while it is going. */
    public function current(SessionInterface $session): ?SignInSession
    {
        $token = $session->get(self::TOKEN);

        return is_string($token) && $token !== '' ? $this->store->active(self::hashOf($token), $this->now()) : null;
    }

    /** @return list<SignInSession> the account's sign-ins still going, the newest first */
    public function of(string $accountId): array
    {
        return $this->store->activeFor($accountId, $this->now());
    }

    /** Ends one of the account's sign-ins by its public id: whether one was going. Never another account's. */
    public function end(string $accountId, string $publicId, ?Actor $actor = null): bool
    {
        if (!$this->store->end($accountId, $publicId, $this->now())) {
            return false;
        }
        $this->activity->record('sign_in.session_ended', [], 'account', $accountId, $actor);

        return true;
    }

    /** Ends every sign-in the account has going but this browser's. Returns how many ended. */
    public function endOthers(string $accountId, SessionInterface $session, ?Actor $actor = null): int
    {
        $current = $this->current($session);
        $ended = 0;
        foreach ($this->of($accountId) as $signIn) {
            if ($signIn->publicId !== $current?->publicId && $this->store->end($accountId, $signIn->publicId, $this->now())) {
                ++$ended;
            }
        }
        if ($ended > 0) {
            $this->activity->record('sign_in.sessions_ended', ['ended' => $ended], 'account', $accountId, $actor);
        }

        return $ended;
    }

    /** Ends this browser's sign-in: signing out. */
    public function finish(SessionInterface $session): void
    {
        $token = $session->remove(self::TOKEN);
        if (is_string($token) && $token !== '') {
            $this->store->endByHash(self::hashOf($token), $this->now());
        }
    }

    private static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
