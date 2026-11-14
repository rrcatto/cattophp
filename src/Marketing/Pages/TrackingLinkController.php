<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Pages;

use CattoPHP\Marketing\Journey;
use CattoPHP\Marketing\Landing\LandingPages;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Landing\LandingViewBuilder;
use CattoPHP\Marketing\Landing\NotRunning;
use CattoPHP\Marketing\LinkOpening;
use CattoPHP\Marketing\Sites\SiteForward;
use CattoPHP\Marketing\TrackedOpening;
use CattoPHP\Marketing\TrackingLinks;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public tracking links (config/routes/marketing_links.php): /c/{campaign} and /c/{campaign}/{publication}. Each
 * records the touch (the click), then:
 *
 * - when the link leads to the campaign's own page (the publication has no destination of its own) and the campaign's
 *   landing page can be seen now, it draws the page in the same answer (200), recording the visit against the same touch
 *   and visitor, so the bare link and the page, which share an address, never count twice;
 * - when the page is set to send visitors elsewhere while the campaign is not running, it forwards (302) there;
 * - otherwise it forwards (302) to where the link leads, as it always did (a draft page, a draft campaign, no page);
 *   where that is on a connected site, a person's recorded click forwards with its signed reference (Sites\SiteForward).
 *
 * An unknown campaign or publication is the site's ordinary not found. Recording never breaks the visit: a failure is
 * logged and the visitor shown the page or forwarded all the same. Nothing here is kept by a browser or a cache.
 */
final class TrackingLinkController
{
    public function __construct(
        private readonly TrackingLinks $links,
        private readonly LoggerInterface $logger,
        private readonly ?LandingPages $pages = null,
        private readonly ?LandingViewBuilder $builder = null,
        private readonly ?Journey $journey = null,
        private readonly ?Campaigns $campaigns = null,
        private readonly ?SiteForward $forward = null,
    ) {
    }

    public function open(Request $request, string $campaign, ?string $publication = null): Response
    {
        $opening = $this->links->open($campaign, $publication, $request) ?? throw new NotFoundHttpException('No such tracking link.');
        $view = null;
        $page = $opening->publication?->destination === null || $opening->publication->destination === '' ? $this->pages?->forCampaign($opening->campaign) : null;
        if ($page !== null && $this->campaigns !== null) {
            $state = NotRunning::for($page, $opening->campaign, $this->campaigns->now());
            if ($state->draws()) {
                $view = $this->builder?->forVisitor($page, $this->journey?->account());
                if ($view !== null) {
                    $opening = new LinkOpening($opening->campaign, $opening->publication, $opening->source, $opening->utm, $view->path);
                }
            } elseif ($state->public && $state->forwardTo !== null) {
                $opening = new LinkOpening($opening->campaign, $opening->publication, $opening->source, $opening->utm, $this->links->forwardAddress($state->forwardTo, $request));
            }
        }
        $tracked = $this->record($opening, $request);
        if ($view !== null) {
            $this->journey?->opened($view, $request, $tracked);
            $response = new Response(($this->builder ?? throw new \LogicException('A page is drawn by the builder that made it.'))->draw($view));
            // The page is the campaign's, at its tracking link's address: a search engine lists neither.
            $robots = 'noindex';
        } else {
            $response = new RedirectResponse($this->forward?->forward($opening->address, $opening, $tracked) ?? $opening->address, Response::HTTP_FOUND);
            $robots = 'noindex, nofollow';
        }
        // Each opening reaches the site: never kept by a browser or a cache.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', $robots);
        if ($tracked?->cookie !== null) {
            $response->headers->setCookie($tracked->cookie);
        }

        return $response;
    }

    /** The opening recorded, or null when it could not be (logged; the visit goes on). */
    private function record(LinkOpening $opening, Request $request): ?TrackedOpening
    {
        try {
            return $this->links->recordOpening($opening, $request);
        } catch (\Throwable $failure) {
            $this->logger->error('A tracking link\'s opening could not be recorded; the visitor was forwarded all the same.', [
                'campaign' => $opening->campaign->code, 'publication' => $opening->publication?->code, 'exception' => $failure,
            ]);

            return null;
        }
    }
}
