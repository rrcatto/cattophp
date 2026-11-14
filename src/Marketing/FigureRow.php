<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

/**
 * One row of a comparison (by campaign, channel, publication, advert, place, country or day): its key, its label and what
 * it belongs to in words (a publication's campaign and channel), with its clicks, unique visitors, repeat visits,
 * openings set aside and spend. A breakdown's spend counts only what belongs to its row (a publication's cost to its
 * channel, place and advert); a campaign's other costs belong to the campaign and its day alone. Later steps add money.
 */
final class FigureRow
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $context = '',
        public readonly int $clicks = 0,
        public readonly int $uniqueVisitors = 0,
        public readonly int $repeatVisits = 0,
        public readonly int $setAside = 0,
        public readonly int $spendMinor = 0,
    ) {
    }

    /** The same row with its spend. */
    public function withSpend(int $spendMinor): self
    {
        return new self($this->key, $this->label, $this->context, $this->clicks, $this->uniqueVisitors, $this->repeatVisits, $this->setAside, $spendMinor);
    }
}
