<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sales;

/**
 * One line of an order, as the project describes it: its key (the project's name for the line, unique within the sale and
 * never shown, such as item:204), the offer it sells (type and reference, as Marketing\Offer names it), its label and the
 * kind's label as they were at the time ("Course"; "Course credits" for places a company buys), how many, its price
 * before any discount (for all of them), the "was" price if there was one, the discount, the part paid with wallet credit,
 * and, for an offer that holds others (a bundle), the offers it held as sold (type:reference), kept for showing and never
 * counted as their sales. Read back from the records, a line also says what was refunded and charged back; a project's
 * report leaves those at nothing.
 *
 * Nothing is refused as the line is made, so building a report never stops a purchase: problems() says what is wrong,
 * and Sales refuses a report with any.
 */
final class SaleLine
{
    /** An offer's type, as Marketing\Events keeps it: small letters, digits, underscores and hyphens. */
    public const OFFER_TYPE = '/^[a-z][a-z0-9_-]{0,39}$/';

    /** @param list<string> $contains the offers a bundle held, each type:reference */
    public function __construct(
        public readonly string $key,
        public readonly string $offerType,
        public readonly string $offerReference,
        public readonly string $label,
        public readonly string $kindLabel,
        public readonly int $quantity,
        public readonly int $priceMinor,
        public readonly ?int $wasMinor = null,
        public readonly int $discountMinor = 0,
        public readonly int $walletMinor = 0,
        public readonly array $contains = [],
        public readonly int $refundedMinor = 0,
        public readonly int $chargedBackMinor = 0,
    ) {
    }

    /** What the line costs after its discount: what is paid for it (wallet credit included). */
    public function paidMinor(): int
    {
        return $this->priceMinor - $this->discountMinor;
    }

    /** What it kept after refunds and chargebacks. */
    public function netMinor(): int
    {
        return $this->paidMinor() - $this->refundedMinor - $this->chargedBackMinor;
    }

    /** The offer's key, type:reference, as Marketing\Offer::key() gives it. */
    public function offerKey(): string
    {
        return $this->offerType . ':' . $this->offerReference;
    }

    /**
     * What is wrong with the line, in words, or nothing.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $name = $this->key === '' ? 'A line' : sprintf('Line "%s"', mb_substr($this->key, 0, 40));
        $problems = [];
        if ($this->key === '' || mb_strlen($this->key) > 120) {
            $problems[] = 'A line\'s key is 1 to 120 characters.';
        }
        if (preg_match(self::OFFER_TYPE, $this->offerType) !== 1) {
            $problems[] = $name . '\'s offer type is small letters, digits, underscores and hyphens, such as product.';
        }
        if (trim($this->offerReference) === '' || mb_strlen($this->offerReference) > 120) {
            $problems[] = $name . '\'s offer reference is 1 to 120 characters.';
        }
        if (trim($this->label) === '' || mb_strlen($this->label) > 200) {
            $problems[] = $name . '\'s label is 1 to 200 characters.';
        }
        if (mb_strlen($this->kindLabel) > 60) {
            $problems[] = $name . '\'s kind label is at most 60 characters.';
        }
        if ($this->quantity < 1) {
            $problems[] = $name . '\'s quantity is 1 or more.';
        }
        if ($this->priceMinor < 0 || ($this->wasMinor !== null && $this->wasMinor < 0) || $this->discountMinor < 0 || $this->walletMinor < 0
            || $this->refundedMinor < 0 || $this->chargedBackMinor < 0) {
            $problems[] = $name . '\'s amounts are nothing or more.';
        } else {
            if ($this->discountMinor > $this->priceMinor) {
                $problems[] = $name . '\'s discount is more than its price.';
            }
            if ($this->walletMinor > max(0, $this->paidMinor())) {
                $problems[] = $name . '\'s wallet credit is more than is paid for it.';
            }
            if ($this->refundedMinor + $this->chargedBackMinor > max(0, $this->paidMinor())) {
                $problems[] = $name . '\'s refunds and chargebacks are more than was paid for it.';
            }
        }
        foreach ($this->contains as $offer) {
            if (preg_match('/^[a-z][a-z0-9_-]{0,39}:\S{1,120}$/', $offer) !== 1) {
                $problems[] = $name . '\'s offers held are each type:reference, such as product:t-100.';
                break;
            }
        }

        return $problems;
    }
}
