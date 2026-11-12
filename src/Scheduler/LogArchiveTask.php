<?php

declare(strict_types=1);

namespace CattoPHP\Scheduler;

use DateTimeImmutable;

/** The log archive as a scheduled task: every day just after midnight. */
final class LogArchiveTask implements ScheduledTask
{
    public function __construct(private readonly LogArchive $archive)
    {
    }

    public function key(): string
    {
        return 'log_archive';
    }

    public function label(): string
    {
        return 'Log archive';
    }

    public function description(): string
    {
        return 'Every day just after midnight: moves the application log, and any other log beside it, into a compressed archive, starts each afresh and deletes the archives older than Settings keep them.';
    }

    public function interval(): int
    {
        return 86400;
    }

    public function runsAt(): string
    {
        return '00:00';
    }

    public function overdueAfter(): int
    {
        return 2 * 86400;
    }

    public function run(DateTimeImmutable $now): string
    {
        return $this->archive->summary($now);
    }
}
