<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sites;

use CattoPHP\Scheduler\ScheduledTask;
use DateTimeImmutable;

/**
 * Every minute: the reports owed to connected sites sent (SiteReports::deliver()), those not delivered in time given up,
 * and those delivered and received long enough ago removed.
 */
final class MarketingSiteReportsTask implements ScheduledTask
{
    public function __construct(private readonly SiteReports $reports)
    {
    }

    public function key(): string
    {
        return 'marketing_site_reports';
    }

    public function label(): string
    {
        return 'Campaign Marketing Manager reports to connected sites';
    }

    public function description(): string
    {
        return 'Every minute: sends connected sites the registrations and sales of the visitors their links brought, tries again those not delivered, and removes old reports.';
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
        return 3600;
    }

    public function run(DateTimeImmutable $now): string
    {
        return $this->reports->deliver($now);
    }
}
