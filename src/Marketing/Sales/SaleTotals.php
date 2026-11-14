<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sales;

/**
 * The totals of the sales a list holds (Sales::totals()), in the site's currency: how many sales are listed, how many of
 * them are paid and by how many paying customers (each account or organisation once; a sale whose buyer is not known
 * counts as one), and of the paid ones the gross revenue (what was paid, wallet credit included), the discounts and
 * wallet credit used (shown beside it, never taken off again), and the refunds and chargebacks of those sales, whenever
 * they were made. Waiting sales are counted with what they are to pay, never added to the revenue; cancelled ones add
 * nothing. A sale in another currency (only possible from a connected site) is left out, counted by its currency in
 * leftOut, so the note can say so.
 */
final class SaleTotals
{
    /** @param array<string,int> $leftOut how many sales in each other currency were left out, by its code */
    public function __construct(
        public readonly ?string $currency,
        public readonly int $sales = 0,
        public readonly int $paidSales = 0,
        public readonly int $payingCustomers = 0,
        public readonly int $grossMinor = 0,
        public readonly int $discountMinor = 0,
        public readonly int $walletMinor = 0,
        public readonly int $refundedMinor = 0,
        public readonly int $chargedBackMinor = 0,
        public readonly int $waitingSales = 0,
        public readonly int $waitingMinor = 0,
        public readonly int $cancelledSales = 0,
        public readonly array $leftOut = [],
    ) {
    }

    /** Net revenue: gross revenue less refunds and chargebacks. */
    public function netMinor(): int
    {
        return $this->grossMinor - $this->refundedMinor - $this->chargedBackMinor;
    }

    /** The average paid order, to the nearest minor unit, or nothing when none was paid. */
    public function averageMinor(): int
    {
        return $this->paidSales === 0 ? 0 : (int) round($this->grossMinor / $this->paidSales);
    }

    /** How many sales in other currencies were left out. */
    public function leftOutSales(): int
    {
        return array_sum($this->leftOut);
    }

    /** The note when sales in other currencies were left out, in words, or an empty string. */
    public function leftOutNote(): string
    {
        $count = $this->leftOutSales();
        if ($count === 0) {
            return '';
        }
        $currencies = array_keys($this->leftOut);
        sort($currencies);

        return sprintf('%s in %s %s listed with %s own currency and left out of these totals.', $count === 1 ? '1 sale' : $count . ' sales',
            count($currencies) === 1 ? $currencies[0] : 'other currencies (' . implode(', ', $currencies) . ')', $count === 1 ? 'is' : 'are', $count === 1 ? 'its' : 'their');
    }
}
