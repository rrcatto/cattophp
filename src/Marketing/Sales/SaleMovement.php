<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sales;

use DateTimeImmutable;

/**
 * Money that moved on a sale, as it happened, so every figure can be traced: the payment, each refund or each chargeback,
 * by the project's own key (paid, refund:<its id>, chargeback:<its id>), kept once whatever is reported again; its
 * amount, when, how much of it was each line's (line key to amount, adding up to the amount), and how, in the project's
 * few words ("Card", "EFT", "Wallet credit", "Money back", "To the wallet").
 */
final class SaleMovement
{
    public const PAID = 'paid';
    public const REFUND = 'refund';
    public const CHARGEBACK = 'chargeback';

    /** The kinds, in words. */
    public const KINDS = [self::PAID => 'Paid', self::REFUND => 'Refund', self::CHARGEBACK => 'Chargeback'];

    /** @param array<string,int> $lines each line's part, by its key */
    public function __construct(
        public readonly int $id,
        public readonly int $saleId,
        public readonly string $kind,
        public readonly string $key,
        public readonly int $amountMinor,
        public readonly DateTimeImmutable $at,
        public readonly array $lines = [],
        public readonly string $how = '',
    ) {
        if (!array_key_exists($kind, self::KINDS)) {
            throw new \InvalidArgumentException(sprintf('A sale\'s money moves as paid, refund or chargeback: "%s" is none of them.', $kind));
        }
        if ($key === '' || mb_strlen($key) > 120) {
            throw new \InvalidArgumentException('A movement\'s key is 1 to 120 characters.');
        }
        if ($amountMinor < 0) {
            throw new \InvalidArgumentException('A movement\'s amount is nothing or more.');
        }
        foreach ($lines as $line => $part) {
            if ($part < 0) {
                throw new \InvalidArgumentException(sprintf('Line "%s"\'s part of a movement is nothing or more.', $line));
            }
        }
        if ($lines !== [] && array_sum($lines) !== $amountMinor) {
            throw new \InvalidArgumentException(sprintf('A movement\'s lines come to %d, not its amount of %d.', array_sum($lines), $amountMinor));
        }
    }

    /** Whether it took money back (a refund or a chargeback). */
    public function takesBack(): bool
    {
        return $this->kind !== self::PAID;
    }

    /** Its kind in words: Paid, Refund, Chargeback. */
    public function kindWords(): string
    {
        return self::KINDS[$this->kind];
    }
}
