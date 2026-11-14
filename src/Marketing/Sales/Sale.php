<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sales;

use DateTimeImmutable;

/**
 * A sale as the records keep it: one for each order the project reported, or each sale a connected site reported
 * (originSite, that site's key). Who bought it (account, organisation; the visitor and country of the browser that placed
 * it, when followed), its state (waiting, paid or cancelled; refunds and chargebacks are amounts, not states), its money
 * (list price, discount, to pay, wallet credit, paid, refunded, charged back), the promotion code used, when it was placed,
 * paid and cancelled, and which campaign gets it: the first and the last counted touch, each with its campaign and
 * publication and how it was found (link, promotion or site), the campaign whose promotion code it used and how that code
 * was set to count, the counting period as it was, when that was worked out, and whether it is fixed (once paid, it
 * never changes). Its lines and movements come with it when read for one sale.
 */
final class Sale
{
    public const WAITING = 'waiting';
    public const PAID = 'paid';
    public const CANCELLED = 'cancelled';

    /** The states a sale is in. */
    public const STATES = [self::WAITING, self::PAID, self::CANCELLED];

    /** What a sale is shown as, worked out from its state and its money (shownState()), in words. */
    public const SHOWN = ['waiting' => 'Waiting for payment', 'paid' => 'Paid', 'cancelled' => 'Cancelled', 'partly_refunded' => 'Partly refunded', 'refunded' => 'Refunded',
        'charged_back' => 'Charged back'];

    /** How a touch was found to count for a sale: a tracking link, only the campaign's promotion code, or a connected site's report. */
    public const BY = ['link' => 'A tracking link', 'promotion' => 'The campaign\'s promotion code', 'site' => 'A connected site'];

    /**
     * @param list<SaleLine> $lines
     * @param list<SaleMovement> $movements
     */
    public function __construct(
        public readonly int $id,
        public readonly string $reference,
        public readonly ?string $originSite,
        public readonly ?string $account,
        public readonly ?string $organisation,
        public readonly ?int $visitorId,
        public readonly string $country,
        public readonly string $state,
        public readonly string $currency,
        public readonly int $listMinor,
        public readonly int $discountMinor,
        public readonly int $totalMinor,
        public readonly int $walletMinor,
        public readonly int $paidMinor,
        public readonly int $refundedMinor,
        public readonly int $chargedBackMinor,
        public readonly ?string $promotion,
        public readonly DateTimeImmutable $placedAt,
        public readonly ?DateTimeImmutable $paidAt,
        public readonly ?DateTimeImmutable $cancelledAt,
        public readonly DateTimeImmutable $updatedAt,
        public readonly ?int $firstTouchId = null,
        public readonly ?int $lastTouchId = null,
        public readonly ?int $firstCampaignId = null,
        public readonly ?int $lastCampaignId = null,
        public readonly ?int $firstPublicationId = null,
        public readonly ?int $lastPublicationId = null,
        public readonly ?string $firstBy = null,
        public readonly ?string $lastBy = null,
        public readonly ?int $promotionCampaignId = null,
        public readonly ?string $promotionCounts = null,
        public readonly ?string $countedWithin = null,
        public readonly ?DateTimeImmutable $attributedAt = null,
        public readonly bool $fixed = false,
        public readonly array $lines = [],
        public readonly array $movements = [],
    ) {
        if (!in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException(sprintf('A sale is waiting, paid or cancelled: "%s" is none of them.', $state));
        }
        foreach ([$firstBy, $lastBy] as $by) {
            if ($by !== null && !array_key_exists($by, self::BY)) {
                throw new \InvalidArgumentException(sprintf('A sale\'s touch is found by link, promotion or site: "%s" is none of them.', $by));
            }
        }
    }

    public function isPaid(): bool
    {
        return $this->state === self::PAID;
    }

    /** Whether a connected site reported it (it was sold there, not here). */
    public function isFromSite(): bool
    {
        return $this->originSite !== null;
    }

    /** What it kept: paid, less refunds and chargebacks. */
    public function netMinor(): int
    {
        return $this->paidMinor - $this->refundedMinor - $this->chargedBackMinor;
    }

    /**
     * What it is shown as: Waiting for payment, Cancelled, Paid, Partly refunded, Refunded (refunds reached what was paid)
     * or Charged back (any chargeback).
     */
    public function shownState(): string
    {
        if ($this->state !== self::PAID) {
            return $this->state;
        }

        return match (true) {
            $this->chargedBackMinor > 0 => 'charged_back',
            $this->refundedMinor > 0 && $this->refundedMinor >= $this->paidMinor => 'refunded',
            $this->refundedMinor > 0 => 'partly_refunded',
            default => self::PAID,
        };
    }

    public function stateWords(): string
    {
        return self::SHOWN[$this->shownState()];
    }

    /** The campaign it counts for under a model, first or last (MARKETING_SALE_COUNTS_FOR), or null: no campaign. */
    public function campaignId(string $model): ?int
    {
        return $model === 'first' ? $this->firstCampaignId : $this->lastCampaignId;
    }

    /** The publication it counts for under a model, first or last, or null. */
    public function publicationId(string $model): ?int
    {
        return $model === 'first' ? $this->firstPublicationId : $this->lastPublicationId;
    }

    /** The touch it counts for under a model, first or last, or null. */
    public function touchId(string $model): ?int
    {
        return $model === 'first' ? $this->firstTouchId : $this->lastTouchId;
    }

    /** How the touch it counts for under a model was found: link, promotion, site, or null. */
    public function by(string $model): ?string
    {
        return $model === 'first' ? $this->firstBy : $this->lastBy;
    }

    /** A line by its key, when the sale was read with its lines. */
    public function line(string $key): ?SaleLine
    {
        foreach ($this->lines as $line) {
            if ($line->key === $key) {
                return $line;
            }
        }

        return null;
    }
}
