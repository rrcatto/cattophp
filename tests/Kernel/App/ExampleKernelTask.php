<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel\App;

use CattoPHP\Scheduler\ScheduledTask;
use DateTimeImmutable;

/** A project's own scheduled task, registered with nothing but autoconfiguration. */
final class ExampleKernelTask implements ScheduledTask
{
    public function key(): string
    {
        return 'reminders';
    }

    public function label(): string
    {
        return 'Reminders';
    }

    public function description(): string
    {
        return 'Sends the reminders that are due.';
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
        return 'No reminders were due.';
    }
}
