<?php

declare(strict_types=1);

namespace CattoPHP\Scheduler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * The runs in the table cattophp_scheduled_jobs (an application's own table of runs moves into it unchanged), each task's
 * last trial beside them (schema step 202611130300-scheduled-job-trials).
 */
final class DbalRunStore implements RunStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function started(string $key, DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'INSERT INTO cattophp_scheduled_jobs (job_key, last_started_at, run_count) VALUES (:job, :at, 1)
             ON CONFLICT (job_key) DO UPDATE SET last_started_at = EXCLUDED.last_started_at, run_count = cattophp_scheduled_jobs.run_count + 1',
            ['job' => $key, 'at' => $at->format(DATE_ATOM)]
        );
    }

    public function succeeded(string $key, DateTimeImmutable $at, string $summary): void
    {
        $this->connection->executeStatement(
            'UPDATE cattophp_scheduled_jobs SET last_finished_at = :at, last_succeeded_at = :at, last_summary = :summary WHERE job_key = :job',
            ['job' => $key, 'at' => $at->format(DATE_ATOM), 'summary' => mb_substr($summary, 0, self::LONGEST)]
        );
    }

    public function failed(string $key, DateTimeImmutable $at, string $error): void
    {
        $this->connection->executeStatement(
            'UPDATE cattophp_scheduled_jobs SET last_finished_at = :at, last_failed_at = :at, last_error = :error WHERE job_key = :job',
            ['job' => $key, 'at' => $at->format(DATE_ATOM), 'error' => mb_substr($error, 0, self::LONGEST)]
        );
    }

    public function tried(string $key, DateTimeImmutable $at, bool $ok, string $summary): void
    {
        $this->connection->executeStatement(
            'INSERT INTO cattophp_scheduled_jobs (job_key, last_trial_at, last_trial_ok, last_trial_summary) VALUES (:job, :at, :ok, :summary)
             ON CONFLICT (job_key) DO UPDATE SET last_trial_at = EXCLUDED.last_trial_at, last_trial_ok = EXCLUDED.last_trial_ok, last_trial_summary = EXCLUDED.last_trial_summary',
            ['job' => $key, 'at' => $at->format(DATE_ATOM), 'ok' => $ok ? 'true' : 'false', 'summary' => mb_substr($summary, 0, self::LONGEST)]
        );
    }

    public function all(): array
    {
        $records = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM cattophp_scheduled_jobs ORDER BY job_key') as $row) {
            $text = static fn(mixed $value): ?string => $value === null ? null : (string) $value;
            $records[(string) $row['job_key']] = [
                'last_started_at' => $text($row['last_started_at']), 'last_finished_at' => $text($row['last_finished_at']),
                'last_succeeded_at' => $text($row['last_succeeded_at']), 'last_failed_at' => $text($row['last_failed_at']),
                'last_summary' => (string) ($row['last_summary'] ?? ''), 'last_error' => (string) ($row['last_error'] ?? ''), 'run_count' => (int) $row['run_count'],
                'last_trial_at' => $text($row['last_trial_at'] ?? null),
                'last_trial_ok' => ($row['last_trial_ok'] ?? null) === null ? null : in_array($row['last_trial_ok'], [true, 1, '1', 't', 'true'], true),
                'last_trial_summary' => (string) ($row['last_trial_summary'] ?? ''),
            ];
        }
        return $records;
    }
}
