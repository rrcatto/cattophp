<?php

declare(strict_types=1);

namespace CattoPHP\Scheduler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Each task's last runs in words, and whether it has stopped, for a project's Settings, dashboard and setup checklist.
 * A task is overdue when it has gone longer than its own overdue time without a successful run. Its last trial (a run as
 * if it were another moment, `--now`) is shown apart, and never counts as a run or keeps a task from being overdue.
 */
final class TaskStatus
{
    public function __construct(private readonly Scheduler $scheduler, private readonly RunStore $runs, private readonly ClockInterface $clock)
    {
    }

    /**
     * Every task, in the scheduler's order, with its last runs.
     *
     * @return list<array{key:string,label:string,description:string,last_run:string,last_success:string,last_failure:?string,error:string,summary:string,overdue:bool,run_count:int,last_trial:?string,trial_summary:string,trial_failed:bool}>
     *     last_trial is when the last trial ran in words (null for none), trial_summary what it said (starting "As at" and
     *     the moment it was given), trial_failed whether it failed
     */
    public function tasks(): array
    {
        $records = $this->runs->all();
        $tasks = [];
        foreach ($this->scheduler->tasks() as $task) {
            $record = $records[$task->key()] ?? [];
            $succeeded = self::time($record['last_succeeded_at'] ?? null);
            $failed = self::time($record['last_failed_at'] ?? null);
            $lastFailed = $failed !== null && ($succeeded === null || $failed > $succeeded);
            $tasks[] = [
                'key' => $task->key(),
                'label' => $task->label(),
                'description' => $task->description(),
                'last_run' => $this->words(self::time($record['last_started_at'] ?? null)) ?? 'Never',
                'last_success' => $this->words($succeeded) ?? 'Never',
                'last_failure' => $lastFailed ? $this->words($failed) : null,
                'error' => $lastFailed ? (string) ($record['last_error'] ?? '') : '',
                'summary' => (string) ($record['last_summary'] ?? ''),
                'overdue' => Schedule::isOverdue($task, $this->now(), $succeeded),
                'run_count' => (int) ($record['run_count'] ?? 0),
                'last_trial' => $this->words(self::time($record['last_trial_at'] ?? null)),
                'trial_summary' => (string) ($record['last_trial_summary'] ?? ''),
                'trial_failed' => ($record['last_trial_ok'] ?? null) === false,
            ];
        }
        return $tasks;
    }

    /** When a task last succeeded, or null if it never has (or there is no such task). */
    public function lastSucceeded(string $key): ?DateTimeImmutable
    {
        return self::time($this->runs->all()[$key]['last_succeeded_at'] ?? null);
    }

    /** Whether a task has gone too long without a successful run; a task the scheduler does not have is not overdue. */
    public function isOverdue(string $key): bool
    {
        $task = $this->scheduler->task($key);
        return $task !== null && Schedule::isOverdue($task, $this->now(), $this->lastSucceeded($key));
    }

    /** A moment as the pages say it: 10:42 when it is today, else 18 October 2026 at 10:42. */
    public function words(?DateTimeImmutable $at): ?string
    {
        if ($at === null) return null;
        $local = $at->setTimezone($this->now()->getTimezone());
        return $local->format('Y-m-d') === $this->now()->format('Y-m-d') ? $local->format('H:i') : $local->format('j F Y \a\t H:i');
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }

    private static function time(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }
}
