<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

/**
 * The figures of a period, for one campaign or all: clicks (people's openings that count, a repeat within the same visit
 * not among them), unique visitors, repeat visits (clicks by visitors who had come before), the openings set aside (link
 * previews, and search engines, AI crawlers and other bots), people's openings while the campaign was not running, the
 * repeats within one visit, and the spend. Later steps add the money a campaign brought in.
 */
final class FigureTotals
{
    public function __construct(
        public readonly int $clicks = 0,
        public readonly int $uniqueVisitors = 0,
        public readonly int $repeatVisits = 0,
        public readonly int $linkPreviews = 0,
        public readonly int $otherSetAside = 0,
        public readonly int $notRunning = 0,
        public readonly int $sameVisit = 0,
        public readonly int $spendMinor = 0,
    ) {
    }

    /** Every opening set aside as not a person's: link previews and the rest. */
    public function setAside(): int
    {
        return $this->linkPreviews + $this->otherSetAside;
    }
}
