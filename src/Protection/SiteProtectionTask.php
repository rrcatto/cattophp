<?php

declare(strict_types=1);

namespace CattoPHP\Protection;

use CattoPHP\Scheduler\ScheduledTask;
use DateTimeImmutable;

/** The rules, every minute, as a scheduled task (its place in the list is `protection.task_priority`). */
final class SiteProtectionTask implements ScheduledTask
{
    public function __construct(private readonly ProtectionRules $rules)
    {
    }

    public function key(): string
    {
        return 'site_protection';
    }

    public function label(): string
    {
        return 'Site protection';
    }

    public function description(): string
    {
        return 'Every minute: checks what each address did against the rules in Site protection settings, flags it and times it out, and takes off the server\'s firewall any lockout that has ended.';
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
        return $this->rules->run();
    }
}
