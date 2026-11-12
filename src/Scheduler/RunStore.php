<?php

declare(strict_types=1);

namespace CattoPHP\Scheduler;

use DateTimeImmutable;

/** Where each task's last runs are kept: when it last started, finished, succeeded and failed, what it said, how often it ran. */
interface RunStore
{
    /** The longest summary or error kept, in characters: the rest of a longer one is cut off. */
    public const LONGEST = 1000;

    public function started(string $key, DateTimeImmutable $at): void;

    public function succeeded(string $key, DateTimeImmutable $at, string $summary): void;

    public function failed(string $key, DateTimeImmutable $at, string $error): void;

    /**
     * Every task's record, by its key.
     *
     * @return array<string,array{last_started_at:?string,last_finished_at:?string,last_succeeded_at:?string,last_failed_at:?string,last_summary:string,last_error:string,run_count:int}>
     */
    public function all(): array;
}
