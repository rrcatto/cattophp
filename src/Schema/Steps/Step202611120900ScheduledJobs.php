<?php

declare(strict_types=1);

namespace CattoPHP\Schema\Steps;

use CattoPHP\Schema\SqlStep;

/**
 * The scheduler's record of each task's last runs (Scheduler\DbalRunStore): when it last started, finished, succeeded and
 * failed, what it said, and how many times it has run. An application's own table of runs in the same form moves in as
 * it stands.
 */
final class Step202611120900ScheduledJobs extends SqlStep
{
    public function id(): string
    {
        return '202611120900-scheduled-jobs';
    }

    public function describe(): string
    {
        return 'Scheduled jobs and their last runs';
    }

    protected function statements(): array
    {
        return [
            'CREATE TABLE cattophp_scheduled_jobs (
                job_key text PRIMARY KEY CHECK (job_key ~ \'^[a-z][a-z0-9_]{0,63}$\'),
                last_started_at timestamptz NULL,
                last_finished_at timestamptz NULL,
                last_succeeded_at timestamptz NULL,
                last_failed_at timestamptz NULL,
                last_summary text NOT NULL DEFAULT \'\',
                last_error text NOT NULL DEFAULT \'\',
                run_count bigint NOT NULL DEFAULT 0 CHECK (run_count >= 0)
            )',
        ];
    }
}
