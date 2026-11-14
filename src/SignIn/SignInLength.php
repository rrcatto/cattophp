<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

use CattoPHP\Settings\Settings;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * How long a sign-in lasts (AUTH_SESSION_TTL), counted from the person's last visit: they stay signed in as long as
 * they come back within it, and are signed out once they have stayed away that long. Closing the browser does not sign
 * them out. Each visit that counts (countsAsVisit(), the one place that decides) moves three things on together:
 *
 * - the sign-in's own end, kept in the session (SignInFlow::visited(), SignInFlow::endsAt()) and checked on every
 *   request (SignInUserProvider), and the kept sign-in where the project keeps them (SignInSessions::visited());
 * - the stored session's lifetime: each request's session storage is given the length as `session.gc_maxlifetime`
 *   (SignInLengthStorageFactory), so the session handler keeps the session that long after it was last used;
 * - the session cookie's expiry: sent again ending with the sign-in, its other flags (HttpOnly, Secure, SameSite, path,
 *   domain) as Symfony makes them. Until someone signs in, the cookie has no expiry and ends when the browser is closed;
 *   at signing in it is sent again lasting the sign-in's length; a cookie being cleared (signing out) is left alone.
 *
 * Every request from the person's browser counts, a page's own refresh too, so a page left open keeps them signed in;
 * one that did not count would move none of them. To cost little, the end is moved only once it would move by
 * moveEvery() or more (a hundredth of the length, at most an hour), so the session is not written, nor the cookie sent
 * again, on every request: a page refreshing every 15 seconds writes once an hour with a long sign-in. A shorter
 * setting applies at once, from the last visit; a longer one from the next visit. Each wins over a project's `framework.session` cookie_lifetime and gc_maxlifetime;
 * `cattophp.sign_in.session_lifetime: false` leaves those two to framework.yaml and keeps only the sign-in's own end.
 */
final class SignInLength implements EventSubscriberInterface
{
    /** Where the sign-in's end, read before the session was saved, waits for the cookie, on the request. */
    private const END = '_cattophp_sign_in_ends';

    /** Whether this request moved the end, so the cookie is sent again. */
    private const MOVED = '_cattophp_sign_in_moved';

    /** Just before Symfony's session listener saves the session (-1000), and just after it sets the cookie. */
    public const VISIT_PRIORITY = -999;
    public const COOKIE_PRIORITY = -1001;

    /** The longest the end may lag behind a visit, in seconds, however long a sign-in lasts. */
    private const MOST_LAG = 3600;

    /**
     * @param array<string,mixed> $sessionOptions framework.session's options (`session.storage.options`), from which
     *                                            Symfony makes the session cookie's flags; given by the bundle
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly ClockInterface $clock,
        private readonly ?SignInSessions $sessions = null,
        private readonly array $sessionOptions = [],
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => [['visit', self::VISIT_PRIORITY], ['keepCookie', self::COOKIE_PRIORITY]]];
    }

    /** How long a sign-in lasts now without a visit, in seconds. */
    public function seconds(): int
    {
        return $this->settings->duration('AUTH_SESSION_TTL')->lengthInSeconds();
    }

    /** When a sign-in visited at $now ends if the person stays away, in seconds. */
    public function endFrom(int $now): int
    {
        return $now + $this->seconds();
    }

    /**
     * Whether this request is a visit, which keeps the sign-in going: the one place that decides. Every request the
     * signed-in person's browser makes counts, a page's own refresh included (a page left open that refreshes itself
     * every 15 or 30 seconds keeps them signed in while it stays open). A rule that left refreshes out would answer
     * `!Http\LiveRefresh::isRefresh($request)` here, with each live page's controller marking its refresh.
     */
    public function countsAsVisit(Request $request): bool
    {
        return true;
    }

    /** How far the end must move before it is written again, in seconds: a hundredth of the length, at most an hour. */
    public static function moveEvery(int $seconds): int
    {
        return max(1, min(self::MOST_LAG, intdiv($seconds, 100)));
    }

    /** A visit that counts moves the sign-in's end on, while the session is still open; notes the end for the cookie. */
    public function visit(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasSession(true)) {
            return;
        }
        $session = $request->getSession();
        if (!$session->isStarted()) {
            return;
        }
        $lasts = $this->seconds();
        $end = SignInFlow::endsAt($session, $lasts);
        $now = $this->clock->now()->getTimestamp();
        if ($end !== null && $end > $now && $this->countsAsVisit($request) && $now + $lasts - $end >= self::moveEvery($lasts)) {
            $end = $now + $lasts;
            (new SignInFlow())->visited($session, $now, $end);
            $this->sessions?->visited($session, (new DateTimeImmutable())->setTimestamp($now), (new DateTimeImmutable())->setTimestamp($end));
            $request->attributes->set(self::MOVED, true);
        }
        $request->attributes->set(self::END, $end);
    }

    /**
     * Gives the session cookie the sign-in's end: the one Symfony is sending (a new session id) ends with it, or, with
     * nobody signed in, has no expiry, so it ends when the browser is closed; and where this request moved the end, the
     * cookie is sent again with it.
     */
    public function keepCookie(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasSession(true)) {
            return;
        }
        $session = $request->getSession();
        $name = $session->getName();
        $now = $this->clock->now()->getTimestamp();
        $end = $request->attributes->get(self::END);
        // 0: no expiry, the cookie ends when the browser is closed, as long as nobody is signed in.
        $expires = is_int($end) && $end > $now ? $end : 0;
        $response = $event->getResponse();
        $sent = false;
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name && !$cookie->isCleared() && $cookie->getValue() !== null && $cookie->getValue() !== '') {
                $response->headers->setCookie($cookie->withExpires($expires));
                $sent = true;
            }
        }
        if (!$sent && $request->attributes->get(self::MOVED) === true && $session->getId() !== '') {
            $options = $this->cookieOptions();
            if ($options['use_cookies']) {
                $response->headers->setCookie(Cookie::create($name, $session->getId(), $expires, $options['path'], $options['domain'], $options['secure'], $options['httponly'], false, $options['samesite']));
            }
        }
    }

    /**
     * The session cookie's flags as Symfony's session listener makes them: PHP's session cookie parameters, then
     * framework.session's own (its cookie_secure: auto left to PHP's, which Symfony sets from the request).
     *
     * @return array{path:string,domain:?string,secure:bool,httponly:bool,samesite:'lax'|'none'|'strict'|null,use_cookies:bool}
     */
    private function cookieOptions(): array
    {
        $options = [];
        foreach (session_get_cookie_params() as $key => $value) {
            $options['cookie_' . $key] = $value;
        }
        foreach ($this->sessionOptions as $key => $value) {
            if ($key !== 'cookie_secure' || $value !== 'auto') {
                $options[$key] = $value;
            }
        }
        $domain = $options['cookie_domain'] ?? null;
        $sameSite = $options['cookie_samesite'] ?? Cookie::SAMESITE_LAX;

        return [
            'path' => is_string($options['cookie_path'] ?? null) && $options['cookie_path'] !== '' ? $options['cookie_path'] : '/',
            'domain' => is_string($domain) && $domain !== '' ? $domain : null,
            'secure' => (bool) ($options['cookie_secure'] ?? false),
            'httponly' => (bool) ($options['cookie_httponly'] ?? true),
            'samesite' => match (is_string($sameSite) ? strtolower($sameSite) : '') {
                Cookie::SAMESITE_LAX => Cookie::SAMESITE_LAX,
                Cookie::SAMESITE_STRICT => Cookie::SAMESITE_STRICT,
                Cookie::SAMESITE_NONE => Cookie::SAMESITE_NONE,
                default => null,
            },
            'use_cookies' => (bool) ($options['use_cookies'] ?? true),
        ];
    }
}
