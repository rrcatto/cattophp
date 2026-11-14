<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

use CattoPHP\Marketing\Destination;
use CattoPHP\Marketing\Journey;
use CattoPHP\Marketing\Offer;
use Symfony\Component\HttpFoundation\Request;

/**
 * The presses of an offer's buttons on landing pages. A POST action's form carries two hidden fields, cattophp_landing
 * (the page's reference) and cattophp_return (the page's address); the project's handler, having accepted the press
 * (an offer added to a basket, a favourite given), calls accepted(), which checks that the page is published, can be
 * seen now and holds the offer, then records the press (Marketing\Journey) and announces it. A project that never calls it
 * records visits and no presses. A GET action goes through the page's own /go/{press} address, which records it with
 * followed() and forwards only to what the section holds.
 */
final class LandingPresses
{
    public const PAGE_FIELD = 'cattophp_landing';
    public const RETURN_FIELD = 'cattophp_return';

    public function __construct(private readonly LandingPages $pages, private readonly Journey $journey)
    {
    }

    /**
     * A press the project accepted: recorded when it came from a published page that is public now and holds the offer.
     * Gives whether it was recorded; a forged or out-of-date page reference is simply not.
     */
    public function accepted(Request $request, Offer $offer, string $action): bool
    {
        $reference = $request->request->get(self::PAGE_FIELD) ?? $request->query->get(self::PAGE_FIELD);
        if (!is_string($reference) || preg_match('/^[a-z0-9]{4,12}$/', $reference) !== 1) {
            return false;
        }
        $page = $this->pages->get($reference);
        if ($page === null || !$this->isPublicNow($page) || !$this->pages->holdsOffer($page, $offer->key())) {
            return false;
        }
        $this->journey->pressed($page, $offer, $action, $request);

        return true;
    }

    /** A GET press through a page's /go/{press} address, once the page has found what it leads to: recorded and announced. */
    public function followed(Request $request, LandingPage $page, string $section, ?Offer $offer, string $action): void
    {
        if ($this->isPublicNow($page)) {
            $this->journey->pressed($page, $offer, $action, $request, $section);
        }
    }

    /** Where a press's form asks to come back to: a path on the site, or null (never another site). */
    public function returnTo(Request $request): ?string
    {
        $path = $request->request->get(self::RETURN_FIELD) ?? $request->query->get(self::RETURN_FIELD);

        return is_string($path) && Destination::isPath($path) && Destination::problem($path) === null ? $path : null;
    }

    /** Whether visitors can see a page now: published, its offer public (an offer's page) or its campaign drawing it. */
    public function isPublicNow(LandingPage $page): bool
    {
        if (!$page->isPublished()) {
            return false;
        }
        $context = $this->pages->context($page);
        if ($page->isOfferPage()) {
            return $context->offer()->public ?? false;
        }

        return NotRunning::for($page, $context->campaign, $context->now)->draws();
    }
}
