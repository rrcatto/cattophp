<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

use CattoPHP\Marketing\MarketingPermissions;
use CattoPHP\Marketing\Offer;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The project's part on landing pages when it gives none: an offer's card is what Marketing\MarketingProject says of it
 * (its name, price, "was" price, a View link to its page, and a note when it cannot be had now), there is no purchase box
 * of the project's own, nothing is added to the frame, and an offer's page is edited by whoever manages campaigns.
 */
final class GenericLanding implements LandingProject
{
    public function __construct(private readonly ?AuthorizationCheckerInterface $authorization = null)
    {
    }

    public function card(Offer $offer, LandingVisit $visit, CardOptions $options): OfferCard
    {
        $actions = [];
        if ($offer->href !== null && $offer->href !== '' && in_array('view', $options->buttons, true)) {
            $actions[] = new OfferAction('view', 'View', 'action-view', href: $offer->href);
        }

        return new OfferCard($offer->key(), $offer->label, (string) $offer->href, price: $offer->price, was: $offer->wasPrice,
            note: $offer->available ? '' : 'Not on sale at the moment.', actions: $actions);
    }

    public function purchase(Offer $offer, LandingVisit $visit, string $style): ?ProjectPart
    {
        return null;
    }

    public function page(LandingView $view): array
    {
        return [];
    }

    public function access(LandingPage $page, ?UserInterface $reader): LandingAccess
    {
        if ($reader === null || $this->authorization === null) {
            return LandingAccess::none();
        }
        if ($this->authorization->isGranted(MarketingPermissions::MANAGE)) {
            return LandingAccess::all();
        }

        return $this->authorization->isGranted(MarketingPermissions::VIEW) ? LandingAccess::viewOnly() : LandingAccess::none();
    }
}
