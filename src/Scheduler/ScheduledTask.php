<?php

declare(strict_types=1);

namespace CattoPHP\Scheduler;

use DateTimeImmutable;

/**
 * One piece of a project's background work, found by its service tag (ScheduledTask::TAG, given by autoconfiguration to
 * every service that implements this). The scheduler runs it when it is due and records each run.
 */
interface ScheduledTask
{
    public const TAG = 'cattophp.scheduled_task';

    /** Its key, which its runs are recorded under: lower-case letters, digits and underscores, such as orders or log_archive. */
    public function key(): string;

    /** Its name in plain words, as Settings and a dashboard show it. */
    public function label(): string;

    /** What it does, in a sentence. */
    public function description(): string;

    /** How often it runs, in seconds; for one with a time of day, the time between those (86400 for daily). */
    public function interval(): int;

    /** The time of day it runs at, H:i in the site's time zone, or null for one run at its interval from its last run. */
    public function runsAt(): ?string;

    /** How long without a successful run before it counts as stopped, in seconds. */
    public function overdueAfter(): int;

    /** Does its work and says what it did in words; it throws to say it failed. */
    public function run(DateTimeImmutable $now): string;
}
