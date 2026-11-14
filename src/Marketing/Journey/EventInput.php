<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Journey;

use DateTimeImmutable;

/**
 * One event of a visitor's journey to record (Marketing\Events::record()): its kind and moment, by which visitor and
 * account, on which campaign, publication and landing page, with the touch it came by, the offer it was about, a small
 * detail (a few words and numbers: never copy, an address or a person), the country, whether the browser asked not to be
 * tracked, an idempotency key (an event with one is kept once, however often it is reported), the other site it came
 * through and the click's reference there, and the sale it belongs to.
 */
final class EventInput
{
    /** @param array<string,mixed> $detail */
    public function __construct(
        public readonly string $kind,
        public readonly DateTimeImmutable $at,
        public readonly ?int $visitorId = null,
        public readonly ?string $account = null,
        public readonly ?int $campaignId = null,
        public readonly ?int $publicationId = null,
        public readonly ?int $touchId = null,
        public readonly ?int $landingPageId = null,
        public readonly ?string $offerType = null,
        public readonly ?string $offerReference = null,
        public readonly array $detail = [],
        public readonly string $country = '',
        public readonly bool $doNotTrack = false,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $originSite = null,
        public readonly ?string $originReference = null,
        public readonly ?int $saleId = null,
    ) {
    }
}
