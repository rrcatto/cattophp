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
    /** interest-cohort is left out: browsers no longer know it, and Chrome reports an unknown one as an error in every page's console. */
    public const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=(), usb=()';

    public function __construct(
        private readonly ContentSecurityPolicy $policy,
        private readonly SiteMode $siteMode,
        private readonly string $referrerPolicy = 'strict-origin-when-cross-origin',
        private readonly bool $noIndex = false,
        private readonly bool $noStore = false,
        private readonly string $permissionsPolicy = self::PERMISSIONS_POLICY,
    ) {
    }

    /**
     * X-Frame-Options as the policy's frame-ancestors says: DENY for 'none', SAMEORIGIN for 'self' (a project that shows
     * its own pages in frames, such as previews), and none for anything else, which only frame-ancestors can say.
     */
    private function frameOptions(): ?string
    {
        return match (trim((string) $this->policy->directive('frame-ancestors'))) {
            "'none'" => 'DENY',
            "'self'" => 'SAMEORIGIN',
            default => null,
        };
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
        // A response that says these for itself keeps what it says.
        $own = static fn (string $name, ?string $value) => $value === null || $headers->has($name) ? null : $headers->set($name, $value);
        $own('X-Content-Type-Options', 'nosniff');
        $own('X-Frame-Options', $this->frameOptions());
        $own('Referrer-Policy', $this->referrerPolicy);
        $own('Permissions-Policy', $this->permissionsPolicy);
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
