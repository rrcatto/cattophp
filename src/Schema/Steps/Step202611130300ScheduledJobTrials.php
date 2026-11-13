<?php

declare(strict_types=1);

namespace CattoPHP\Schema\Steps;

use CattoPHP\Schema\SqlStep;

/**
 * Each scheduled task's last trial (a run as if it were another moment, `cattophp:schedule:run --job --now`), kept apart
 * from its runs (Scheduler\DbalRunStore::tried()): when it ran, whether it worked and what it said. A trial never changes
 * the task's last start or success or how often it ran, so it never moves the schedule.
 */
final class Step202611130300ScheduledJobTrials extends SqlStep
{
    public function id(): string
    {
        return '202611130300-scheduled-job-trials';
    }

    public function describe(): string
    {
        return 'Each scheduled job\'s last trial, apart from its runs';
    }

    protected function statements(): array
    {
        return [
            'ALTER TABLE cattophp_scheduled_jobs
                ADD COLUMN last_trial_at timestamptz NULL,
                ADD COLUMN last_trial_ok boolean NULL,
                ADD COLUMN last_trial_summary text NOT NULL DEFAULT \'\'',
        ];
    }
}
