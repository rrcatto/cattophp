<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Pages;

use CattoPHP\Marketing\Visitor;
use DateTimeImmutable;

/**
 * One row of the Visitors list: a person with all their visitors together (their phone, their computer), or a visitor
 * nobody knows yet. Its reference is its first visitor's, the address of its journey; its account, when known; when it
 * was first and last seen; the countries and devices seen, the first first; its visits (counted clicks and comings
 * back), the campaigns it came by, and its state (PersonJourney's: paying, waiting, registered, not_registered).
 */
final class VisitorRow
{
    /**
     * @param list<string> $references every visitor's reference, the first seen first
     * @param list<string> $countries two capital letters each
     * @param list<string> $devices Visitor::DEVICES' keys, never unknown
     */
    public function __construct(
        public readonly string $reference,
        public readonly ?string $account,
        public readonly array $references,
        public readonly DateTimeImmutable $firstSeenAt,
        public readonly DateTimeImmutable $lastSeenAt,
        public readonly array $countries,
        public readonly array $devices,
        public readonly int $visits,
        public readonly int $campaigns,
        public readonly string $state,
    ) {
    }

    /** The devices in words: "A phone", "A phone and a computer", "A phone, a tablet and a computer"; '' when none is known. */
    public function devicesWords(): string
    {
        $words = array_map(static fn (string $device): string => 'a ' . strtolower(Visitor::DEVICES[$device] ?? $device), $this->devices);
        if ($words === []) {
            return '';
        }
        $last = array_pop($words);

        return ucfirst($words === [] ? $last : implode(', ', $words) . ' and ' . $last);
    }
}
