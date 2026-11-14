<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Journey;

use CattoPHP\Marketing\Journey;
use CattoPHP\Marketing\Visitors;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Notices a followed visitor coming back (Marketing\Journey::cameBack()), on the kernel.request of a page: a main GET
 * that accepts HTML, is not an htmx request and carries the visitor cookie, under none of the tracking links
 * (/{link_prefix}/), the Campaign Marketing Manager's pages (/marketing), webhooks (/webhooks/) or the project's own
 * exclusions (cattophp.marketing.returns_excluded). It moves the visitor's last_seen_at on, so the history setting
 * counts from their last activity; the cookie's life is restarted only by a tracking link. A browser without the cookie
 * costs nothing. On while cattophp.marketing.returns is true (the default).
 */
final class VisitorReturns
{
    /** @var list<string> */
    private readonly array $excluded;

    /** @param list<string> $excluded the starts of paths where a return is never noticed */
    public function __construct(private readonly Journey $journey, string $linkPrefix = 'c', array $excluded = [])
    {
        $this->excluded = ['/' . $linkPrefix . '/', '/marketing', '/webhooks/', ...$excluded];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod('GET') || !is_string($request->cookies->get(Visitors::COOKIE)) || $request->headers->has('HX-Request')) {
            return;
        }
        $accept = (string) $request->headers->get('Accept', '');
        if (!str_contains($accept, 'text/html') && !str_contains($accept, 'application/xhtml+xml')) {
            return;
        }
        $path = $request->getPathInfo();
        foreach ($this->excluded as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
                return;
            }
        }
        $this->journey->cameBack($request);
    }
}
