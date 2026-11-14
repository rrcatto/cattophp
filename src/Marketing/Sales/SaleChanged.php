<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sales;

/**
 * Announced by Marketing\Sales inside the same transaction as the change, so what a listener writes (a connected site's
 * report) goes in with it or not at all: the sale as it is now (with its lines), what changed (placed, paid, cancelled,
 * refunded, charged_back, or reported, by a connected site), and for a refund or chargeback the movement it added.
 */
final class SaleChanged
{
    public const PLACED = 'placed';
    public const PAID = 'paid';
    public const CANCELLED = 'cancelled';
    public const REFUNDED = 'refunded';
    public const CHARGED_BACK = 'charged_back';
    public const REPORTED = 'reported';

    /** What may have changed. */
    public const CHANGES = [self::PLACED, self::PAID, self::CANCELLED, self::REFUNDED, self::CHARGED_BACK, self::REPORTED];

    public function __construct(public readonly Sale $sale, public readonly string $change, public readonly ?SaleMovement $movement = null)
    {
        if (!in_array($change, self::CHANGES, true)) {
            throw new \InvalidArgumentException(sprintf('A sale is placed, paid, cancelled, refunded, charged_back or reported: "%s" is none of them.', $change));
        }
    }
}
