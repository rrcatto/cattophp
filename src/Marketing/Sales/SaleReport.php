<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sales;

use DateTimeImmutable;

/**
 * An order as the project describes it to Marketing\Sales, the same whole description every time it reports on it:
 * its reference (the project's order number, such as ORD-1042), the buyer's account (or null), the buying organisation's
 * reference (or null), when it was placed, its currency (three capital letters), what is to be paid (wallet credit
 * included), the part paid with wallet credit, the promotion code used (or null) and its lines (SaleLine). The lines'
 * prices less their discounts add up to what is to be paid.
 *
 * Nothing is refused as the report is made, so building one never stops a purchase: problems() says what is wrong, and
 * Sales refuses a report with any, writing the reason to the log.
 */
final class SaleReport
{
    /** A sale's reference: 1 to 60 characters, no spaces. */
    public const REFERENCE = '/^\S{1,60}$/u';

    /** @param list<SaleLine> $lines */
    public function __construct(
        public readonly string $reference,
        public readonly ?string $account,
        public readonly ?string $organisation,
        public readonly DateTimeImmutable $placedAt,
        public readonly string $currency,
        public readonly int $totalMinor,
        public readonly int $walletMinor = 0,
        public readonly ?string $promotion = null,
        public readonly array $lines = [],
    ) {
    }

    /** The lines' prices before discounts: what it would have cost with none. */
    public function listMinor(): int
    {
        return array_sum(array_map(static fn(SaleLine $line): int => $line->priceMinor, $this->lines));
    }

    /** The lines' discounts together. */
    public function discountMinor(): int
    {
        return array_sum(array_map(static fn(SaleLine $line): int => $line->discountMinor, $this->lines));
    }

    /** A line by its key, or null. */
    public function line(string $key): ?SaleLine
    {
        foreach ($this->lines as $line) {
            if ($line->key === $key) {
                return $line;
            }
        }

        return null;
    }

    /**
     * What is wrong with the report, in words, or nothing: its own values, each line's, and whether the lines add up.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        if (preg_match(self::REFERENCE, $this->reference) !== 1) {
            $problems[] = 'A sale\'s reference is 1 to 60 characters with no spaces.';
        }
        if ($this->account !== null && trim($this->account) === '') {
            $problems[] = 'A sale\'s account is null or names one.';
        }
        if ($this->organisation !== null && (trim($this->organisation) === '' || mb_strlen($this->organisation) > 120)) {
            $problems[] = 'A sale\'s organisation is null or 1 to 120 characters.';
        }
        if (preg_match('/^[A-Z]{3}$/', $this->currency) !== 1) {
            $problems[] = sprintf('A sale\'s currency is three capital letters, such as ZAR: "%s" is not.', mb_substr($this->currency, 0, 10));
        }
        if ($this->promotion !== null && (trim($this->promotion) === '' || mb_strlen($this->promotion) > 120)) {
            $problems[] = 'A sale\'s promotion code is null or 1 to 120 characters.';
        }
        if ($this->totalMinor < 0 || $this->walletMinor < 0) {
            $problems[] = 'A sale\'s amounts are nothing or more.';
        } elseif ($this->walletMinor > $this->totalMinor) {
            $problems[] = 'A sale\'s wallet credit is more than is to be paid.';
        }
        if ($this->lines === []) {
            $problems[] = 'A sale has at least one line.';

            return $problems;
        }
        $keys = [];
        foreach ($this->lines as $line) {
            $problems = [...$problems, ...$line->problems()];
            if (isset($keys[$line->key])) {
                $problems[] = sprintf('Line "%s" is given twice: each line\'s key is its own.', mb_substr($line->key, 0, 40));
            }
            $keys[$line->key] = true;
        }
        $paid = array_sum(array_map(static fn(SaleLine $line): int => $line->paidMinor(), $this->lines));
        if ($paid !== $this->totalMinor) {
            $problems[] = sprintf('The lines\' prices less their discounts come to %d, not the %d to be paid.', $paid, $this->totalMinor);
        }
        // The lines' wallet credit, when they give it, adds up to the sale's; a project may give it for the sale alone.
        $wallet = array_sum(array_map(static fn(SaleLine $line): int => $line->walletMinor, $this->lines));
        if ($wallet !== 0 && $wallet !== $this->walletMinor) {
            $problems[] = sprintf('The lines\' wallet credit comes to %d, not the sale\'s %d.', $wallet, $this->walletMinor);
        }

        return $problems;
    }
}
