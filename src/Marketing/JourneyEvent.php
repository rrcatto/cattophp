<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use DateTimeImmutable;

/**
 * One event of a visitor's journey: what happened (its kind, one of Events::KINDS or a project's own, such as
 * landing_opened or offer_viewed), when, by which visitor and account, on which campaign, publication and landing page, with the touch of the same
 * request, the offer it was about, a small detail (the action, the section's reference: never copy, an address or a
 * person), the country, whether the browser asked not to be tracked, the other site it came through, and the sale it
 * belongs to (an order placed, paid, refunded...).
 */
final class JourneyEvent
{
    /** @param array<string,scalar|null> $detail */
    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly DateTimeImmutable $at,
        public readonly ?int $visitorId,
        public readonly ?string $account,
        public readonly ?int $campaignId,
        public readonly ?int $publicationId,
        public readonly ?int $touchId,
        public readonly ?int $landingPageId,
        public readonly ?string $offerType,
        public readonly ?string $offerReference,
        public readonly array $detail,
        public readonly string $country,
        public readonly bool $doNotTrack,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $originSite = null,
        public readonly ?string $originReference = null,
        public readonly ?int $saleId = null,
    ) {
    }
}
