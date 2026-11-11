<?php

declare(strict_types=1);

namespace CattoPHP\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * A live page's own refresh (@CattoPHP/page/live-status.html.twig asks for the page again every 15 or 30 seconds, with
 * the header X-Live-Refresh, through htmx): the same page drawn again, never a visit. A controller of a live page calls
 * notAVisit(); whatever counts visits (a site log) leaves out a request isRefresh() says is one.
 */
final class LiveRefresh
{
    /** The request attribute that marks a live page's refresh. */
    public const ATTRIBUTE = 'cattophp.live_refresh';

    private function __construct()
    {
    }

    /** Marks the request as the live page's own refresh when it is one: a GET htmx sent with X-Live-Refresh. */
    public static function notAVisit(Request $request): void
    {
        if ($request->isMethod('GET') && $request->headers->has('X-Live-Refresh') && $request->headers->has('HX-Request')) {
            $request->attributes->set(self::ATTRIBUTE, true);
        }
    }

    /** Whether the request was marked as a live page's own refresh. */
    public static function isRefresh(Request $request): bool
    {
        return $request->attributes->get(self::ATTRIBUTE) === true;
    }
}
