<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use CattoPHP\Scheduler\ScheduledTask;
use CattoPHP\Scheduler\ScheduleRunCommand;
use DateTimeImmutable;

/** A scheduled task during which the process is told to stop, as a container is stopped while a task runs. */
final class StoppingTask implements ScheduledTask
{
    public int $runs = 0;

    public ?ScheduleRunCommand $command = null;

    public function key(): string
    {
        return 'orders';
    }

    public function label(): string
    {
        return 'Orders';
    }

    public function description(): string
    {
        return 'Told to stop while it runs.';
    }

    public function interval(): int
    {
        return 60;
    }

    public function runsAt(): ?string
    {
        return null;
    }

    public function overdueAfter(): int
    {
        return 300;
    }

    public function run(DateTimeImmutable $now): string
    {
        $this->runs++;
        $this->command?->handleSignal(15);

        return 'Finished, though told to stop.';
    }
}
