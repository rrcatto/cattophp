<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

/**
 * A campaign as the list of campaigns shows it: the campaign with how many publications it has, its clicks and unique
 * visitors over its whole life, and its spend (its publications' costs and other spend) against its budget.
 */
final class CampaignSummary
{
    public function __construct(
        public readonly Campaign $campaign,
        public readonly int $publications,
        public readonly int $clicks,
        public readonly int $uniqueVisitors,
        public readonly int $spendMinor,
    ) {
    }

    /** Whether its spend has gone past its budget. */
    public function overBudget(): bool
    {
        return $this->campaign->budgetMinor !== null && $this->spendMinor > $this->campaign->budgetMinor;
    }

    /** How much of its budget is spent, 0 to 100 and past it, or null without a budget. */
    public function budgetUsedPercent(): ?int
    {
        $budget = $this->campaign->budgetMinor;
        if ($budget === null) {
            return null;
        }

        return $budget === 0 ? ($this->spendMinor > 0 ? 100 : 0) : (int) round($this->spendMinor * 100 / $budget);
    }
}
