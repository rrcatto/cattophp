<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

use Symfony\Component\Security\Core\User\UserInterface;
use CattoPHP\Marketing\Offer;

/**
 * How the project shows and sells its offers on a landing page, and what its frame needs: config
 * cattophp.marketing.landing_project, a service id; GenericLanding when none is given. Marketing\MarketingProject says what
 * an offer is; this says how it looks to a reader and how it is bought, so CattoPHP never knows what a product or a cart is.
 *
 * A POST action's handler, having accepted a press from a landing page (the fields cattophp_landing and cattophp_return
 * arrive with it), calls LandingPresses::accepted(), which records the press.
 */
interface LandingProject
{
    /** One offer's card for this reader: picture, badges, line, facts, prices and what they can do now. */
    public function card(Offer $offer, LandingVisit $visit, CardOptions $options): OfferCard;

    /** The project's own purchase box ('full' or 'compact'), or null to draw the card's own buy action. */
    public function purchase(Offer $offer, LandingVisit $visit, string $style): ?ProjectPart;

    /** @return array<string,mixed> what the project's frame reads for this page: its head (page_meta), its menu's mark, what to load */
    public function page(LandingView $view): array;

    /** Who may see the editor of, change, publish, upload to and see the versions of an offer's page. */
    public function access(LandingPage $page, ?UserInterface $reader): LandingAccess;
}
