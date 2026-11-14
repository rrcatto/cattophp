<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sites;

use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\LinkOpening;
use CattoPHP\Marketing\Touches;
use CattoPHP\Marketing\TrackingLinks;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * A visitor arriving by a connected site's tracking link (kernel.request, a main GET carrying cattophp_from): the
 * signature is checked (SiteForward::read()), and when it passes the campaign is found by its code, or made for that
 * site (Campaigns::makeForArrival()), and the arrival recorded as a touch through TrackingLinks::recordOpening(), with
 * the other site, its click's reference and its publication's code, the visitor and cookie following step 1's rules.
 * Whatever happens the answer is a 302 to the same address without the two values, so the token never stays in the
 * address or the browser's history. A refused arrival records nothing and the visitor is told nothing: the site's card
 * says why. A replayed one records nothing, its click being recorded once. A failure is logged and never stops the visit.
 *
 * It runs before the router and the firewall, so a page that needs a sign-in still keeps the arrival.
 */
final class ArrivalListener
{
    /** Before the router (32) and the firewall (8): the arrival is answered whatever the address and whoever may see it. */
    public const PRIORITY = 40;

    public function __construct(
        private readonly SiteForward $forward,
        private readonly Sites $sites,
        private readonly Campaigns $campaigns,
        private readonly TrackingLinks $links,
        private readonly Touches $touches,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !in_array($request->getMethod(), ['GET', 'HEAD'], true) || !$request->query->has(SiteForward::FROM)) {
            return;
        }
        $response = new RedirectResponse(self::without($request), Response::HTTP_FOUND);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $now = $this->clock->now();
        $query = $request->query->all();
        $from = is_string($query[SiteForward::FROM] ?? null) ? $query[SiteForward::FROM] : '';
        $signature = is_string($query[SiteForward::SIGNATURE] ?? null) ? $query[SiteForward::SIGNATURE] : '';
        try {
            $arrival = $this->forward->read($from, $signature, $now);
        } catch (SiteRefused $refused) {
            $this->refused($refused, $now);
            $event->setResponse($response);

            return;
        }
        try {
            $this->record($arrival, $request, $response);
            $this->sites->heardFrom($arrival->site, $now);
        } catch (\Throwable $failure) {
            $this->logger->error('An arrival from a connected site could not be recorded; the visitor went on all the same.', ['site' => $arrival->site->key, 'exception' => $failure]);
        }
        $event->setResponse($response);
    }

    /** The arrival's touch recorded (once for its click) and its cookie given to the answer. */
    private function record(Arrival $arrival, Request $request, Response $response): void
    {
        if ($this->touches->byOrigin($arrival->site->key, $arrival->click) !== null) {
            // A replay within the time allowed: the click is recorded already.
            return;
        }
        $campaign = $this->campaigns->makeForArrival($arrival->campaignCode, $arrival->campaignName, $arrival->site);
        $opening = new LinkOpening($campaign, null, $arrival->channel, [], self::path($request));
        $tracked = $this->links->recordOpening($opening, $request, $arrival->site->key, $arrival->click, $arrival->publicationCode);
        if ($tracked->cookie !== null) {
            $response->headers->setCookie($tracked->cookie);
        }
    }

    private function refused(SiteRefused $refused, \DateTimeImmutable $now): void
    {
        $this->logger->notice('An arrival by a connected site\'s link was refused: ' . $refused->getMessage() . '.', ['site' => $refused->site?->key]);
        if ($refused->site === null) {
            return;
        }
        try {
            $this->sites->refused($refused->site, 'arrival: ' . $refused->getMessage() . '.', $now);
        } catch (\Throwable $failure) {
            $this->logger->error('A refused arrival could not be noted on its connected site.', ['site' => $refused->site->key, 'exception' => $failure]);
        }
    }

    /** The address asked for, without the two values. */
    private static function without(Request $request): string
    {
        $query = $request->query->all();
        unset($query[SiteForward::FROM], $query[SiteForward::SIGNATURE]);

        return $request->getBaseUrl() . $request->getPathInfo() . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    /** The path the visitor landed on, as a touch keeps it. */
    private static function path(Request $request): string
    {
        $query = $request->query->all();
        unset($query[SiteForward::FROM], $query[SiteForward::SIGNATURE]);

        return $request->getPathInfo() . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }
}
