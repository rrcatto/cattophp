<?php

declare(strict_types=1);

namespace CattoPHP\Http;

use CattoPHP\Runtime\SiteMode;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * The headers every answer carries, whatever the web server in front does: the Content-Security-Policy, nosniff, no
 * framing, the referrer policy, a permissions policy that turns off what the site never uses, HSTS over HTTPS, the
 * request's reference, and `noindex, nofollow` on a demo site (or always, when the project says so). A project that
 * wants nothing kept by a browser (a page behind sign-in only) sets `no_store`.
 */
final class SecurityHeadersListener
{
    public const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()';

    public function __construct(
        private readonly ContentSecurityPolicy $policy,
        private readonly SiteMode $siteMode,
        private readonly string $referrerPolicy = 'strict-origin-when-cross-origin',
        private readonly bool $noIndex = false,
        private readonly bool $noStore = false,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            RequestReference::of($event->getRequest());
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $headers = $event->getResponse()->headers;
        if (!$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->policy->header($request));
        }
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', $this->referrerPolicy);
        $headers->set('Permissions-Policy', self::PERMISSIONS_POLICY);
        $headers->set(RequestReference::HEADER, RequestReference::of($request));
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        if ($this->noIndex || $this->siteMode->isDemo()) {
            $headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
        if ($this->noStore) {
            $headers->set('Cache-Control', 'no-store');
        }
        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }
    }
}
