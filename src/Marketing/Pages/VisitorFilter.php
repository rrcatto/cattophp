<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Pages;

use DateTimeImmutable;

/**
 * Which people the Visitors list holds: those last seen within a period (from, up to but not including until), in a
 * state (everyone; not registered; registered; an order waiting for payment; a paying customer), who came by a campaign
 * (its id), seen in a country (two capital letters), and matching a search (a name, email address or account reference,
 * as the project's own search of its people finds them, or a visitor's reference). Values that cannot be are refused as
 * it is made.
 */
final class VisitorFilter
{
    /** The states the list may be narrowed to, in words, with the icon of each card. */
    public const STATES = [
        'everyone' => ['Everyone', 'users'],
        'not_registered' => ['Not registered', 'user-x'],
        'registered' => ['Registered', 'user-check'],
        'waiting' => ['Waiting for payment', 'hourglass'],
        'paying' => ['Paying customer', 'badge-dollar-sign'],
    ];

    public function __construct(
        public readonly ?DateTimeImmutable $from = null,
        public readonly ?DateTimeImmutable $until = null,
        public readonly string $state = 'everyone',
        public readonly ?int $campaignId = null,
        public readonly ?string $country = null,
        public readonly string $search = '',
    ) {
        if ($from !== null && $until !== null && $until <= $from) {
            throw new \InvalidArgumentException('A period of visitors ends after it starts.');
        }
        if (!isset(self::STATES[$state])) {
            throw new \InvalidArgumentException(sprintf('Visitors are narrowed to %s: "%s" is none of them.', implode(', ', array_keys(self::STATES)), $state));
        }
        if ($country !== null && preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new \InvalidArgumentException(sprintf('A country is two capital letters, such as ZA: "%s" is not.', $country));
        }
    }

    /** The same people in every state: what the state cards count. */
    public function everyone(): self
    {
        return new self($this->from, $this->until, 'everyone', $this->campaignId, $this->country, $this->search);
    }
}
