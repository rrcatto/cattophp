<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sales;

/**
 * One offer's sales among those a list holds (Sales::offers()): the offer (type and reference), its label and kind's label
 * as last sold, in how many orders it was sold, how many units (a company's places count each), and what its lines were
 * paid, refunded and charged back. A bundle counts as itself; the offers it held are never counted as their sales.
 */
final class OfferSales
{
    public function __construct(
        public readonly string $offerType,
        public readonly string $offerReference,
        public readonly string $label,
        public readonly string $kindLabel,
        public readonly int $orders,
        public readonly int $units,
        public readonly int $grossMinor,
        public readonly int $refundedMinor,
        public readonly int $chargedBackMinor,
    ) {
    }

    /** The offer's key, type:reference, as Marketing\Offer::key() gives it. */
    public function offerKey(): string
    {
        return $this->offerType . ':' . $this->offerReference;
    }

    /** What it kept: paid, less refunds and chargebacks. */
    public function netMinor(): int
    {
        return $this->grossMinor - $this->refundedMinor - $this->chargedBackMinor;
    }
}
