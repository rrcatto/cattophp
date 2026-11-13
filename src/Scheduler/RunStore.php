<?php

declare(strict_types=1);

namespace CattoPHP\Scheduler;

use DateTimeImmutable;

/**
 * Where each task's last runs are kept: when it last started, finished, succeeded and failed, what it said, how often it
 * ran; and, apart from those, its last trial: a run as if it were another moment (`--now`), which never counts as a run.
 */
interface RunStore
{
    /** The longest summary or error kept, in characters: the rest of a longer one is cut off. */
    public const LONGEST = 1000;

    public function started(string $key, DateTimeImmutable $at): void;

    public function succeeded(string $key, DateTimeImmutable $at, string $summary): void;

    public function failed(string $key, DateTimeImmutable $at, string $error): void;

    /**
     * A trial run (Scheduler::run() with $at): when it ran, at the real time, whether it worked and what it said, kept
     * apart from the task's runs, whose record (its last start and success, how often it ran) it leaves as it was, so the
     * schedule and whether the task is overdue are never moved by it.
     */
    public function tried(string $key, DateTimeImmutable $at, bool $ok, string $summary): void;

    /**
     * Every task's record, by its key.
     *
     * @return array<string,array{last_started_at:?string,last_finished_at:?string,last_succeeded_at:?string,last_failed_at:?string,last_summary:string,last_error:string,run_count:int,last_trial_at:?string,last_trial_ok:?bool,last_trial_summary:string}>
     */
    public function all(): array;
}
