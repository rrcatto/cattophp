<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Journey;

use CattoPHP\Marketing\Sales\Sale;
use CattoPHP\Marketing\Visitor;
use DateTimeImmutable;

/**
 * One person's journey (Journey\PersonJourneys::of()): the visitor asked for and, when they have an account, every
 * visitor tied to it (their phone, their computer), their lines oldest first (each click on a tracking link, counted or
 * not; each event; each money movement of their sales), their figures (visits: counted clicks and comings back;
 * campaigns they came by; orders; paid orders and what they paid), their state (a paying customer, an order waiting for
 * payment, registered, or not registered), and the devices and countries seen. Its sales are whole (Sales\Sale, with
 * their lines and movements), the oldest first.
 */
final class PersonJourney
{
    public const PAYING = 'paying';
    public const WAITING = 'waiting';
    public const REGISTERED = 'registered';
    public const NOT_REGISTERED = 'not_registered';

    public const STATES = [self::PAYING => 'Paying customer', self::WAITING => 'Order waiting for payment', self::REGISTERED => 'Registered', self::NOT_REGISTERED => 'Not registered'];

    /**
     * @param list<Visitor> $visitors every visitor of the person, the first seen first
     * @param list<JourneyLine> $lines oldest first
     * @param list<int> $campaignIds the campaigns they came by (a counted click), the first first
     * @param list<Sale> $sales
     * @param list<string> $devices the devices seen (Visitor::DEVICES' keys, never unknown), the first seen first
     * @param list<string> $countries the countries seen (two capital letters), the first seen first
     */
    public function __construct(
        public readonly Visitor $visitor,
        public readonly ?string $account,
        public readonly array $visitors,
        public readonly array $lines,
        public readonly int $visits,
        public readonly array $campaignIds,
        public readonly array $sales,
        public readonly string $state,
        public readonly array $devices,
        public readonly array $countries,
    ) {
    }

    public function stateWords(): string
    {
        return self::STATES[$this->state] ?? $this->state;
    }

    /** How many campaigns they came by. */
    public function campaigns(): int
    {
        return count($this->campaignIds);
    }

    /** How many orders they placed (any state). */
    public function orders(): int
    {
        return count($this->sales);
    }

    /** How many of their orders were paid. */
    public function paidOrders(): int
    {
        return count(array_filter($this->sales, static fn(Sale $sale): bool => $sale->isPaid()));
    }

    /** What their paid orders were paid, in minor units (before refunds and chargebacks). */
    public function paidMinor(): int
    {
        return array_sum(array_map(static fn(Sale $sale): int => $sale->paidMinor, $this->sales));
    }

    /** The devices in words, in the order they were first seen: "A phone, then a computer"; '' when none is known. */
    public function devicesWords(): string
    {
        $words = array_map(static fn(string $device): string => strtolower(Visitor::DEVICES[$device] ?? $device), $this->devices);
        if ($words === []) {
            return '';
        }

        return ucfirst('a ' . implode(', then a ', $words));
    }

    /** When the person was first seen: their earliest visitor's first moment. */
    public function firstSeenAt(): DateTimeImmutable
    {
        $first = $this->visitor->firstSeenAt;
        foreach ($this->visitors as $visitor) {
            $first = min($first, $visitor->firstSeenAt);
        }

        return $first;
    }

    /** When the person was last seen. */
    public function lastSeenAt(): DateTimeImmutable
    {
        $last = $this->visitor->lastSeenAt;
        foreach ($this->visitors as $visitor) {
            $last = max($last, $visitor->lastSeenAt);
        }

        return $last;
    }

    public function isKnown(): bool
    {
        return $this->account !== null;
    }
}
