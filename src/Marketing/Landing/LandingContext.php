<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

use CattoPHP\Marketing\Campaign;
use CattoPHP\Marketing\Offer;
use DateTimeImmutable;

/**
 * The landing page being edited or drawn, as a section kind sees it: a campaign's page or an offer's, the campaign (if
 * any) and whether it is running now, the offers on the page (the campaign's, or the one offer) as the project gives them
 * now, the kinds the page holds, the moment (in the installation's time zone), the project's word for its offers and the
 * campaign's promotion.
 */
final class LandingContext
{
    public const CAMPAIGN = 'campaign';
    public const OFFER = 'offer';

    /**
     * @param list<Offer> $offers
     * @param list<string> $kindsOnPage the kinds of the page's sections, in order
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?Campaign $campaign,
        public readonly bool $running,
        public readonly array $offers,
        public readonly DateTimeImmutable $now,
        public readonly ?LandingPage $page = null,
        public readonly array $kindsOnPage = [],
        public readonly string $offersHeading = 'Offers',
        public readonly string $offerWord = 'offer',
        public readonly ?string $promotion = null,
    ) {
        if (!in_array($kind, [self::CAMPAIGN, self::OFFER], true)) {
            throw new \InvalidArgumentException('A landing page is a campaign\'s or an offer\'s.');
        }
    }

    public function isCampaignPage(): bool
    {
        return $this->kind === self::CAMPAIGN;
    }

    public function isOfferPage(): bool
    {
        return $this->kind === self::OFFER;
    }

    /** The page's own offer (an offer page's), or the first of a campaign's, or null. */
    public function offer(): ?Offer
    {
        return $this->offers[0] ?? null;
    }

    /** One of the page's offers by its key (type:reference), or null. */
    public function offerByKey(string $key): ?Offer
    {
        foreach ($this->offers as $offer) {
            if ($offer->key() === $key) {
                return $offer;
            }
        }

        return null;
    }

    /** Whether the page holds a section of a kind. */
    public function holds(string $kind): bool
    {
        return in_array($kind, $this->kindsOnPage, true);
    }
}
