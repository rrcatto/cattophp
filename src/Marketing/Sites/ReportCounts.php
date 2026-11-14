<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sites;

use DateTimeImmutable;

/**
 * How a connected site's reports stand, for its card: those waiting to be delivered (still being tried, the next try at
 * nextTry), those given up after MARKETING_SITE_REPORTS_KEPT (Send them again offers them once more), those delivered and
 * those received from it, not removed yet.
 */
final class ReportCounts
{
    public function __construct(
        public readonly int $waiting,
        public readonly int $givenUp,
        public readonly int $delivered,
        public readonly int $received,
        public readonly ?DateTimeImmutable $nextTry = null,
    ) {
    }

    /** Reports not delivered, whether still being tried or given up: what removing the site would drop. */
    public function undelivered(): int
    {
        return $this->waiting + $this->givenUp;
    }
}
