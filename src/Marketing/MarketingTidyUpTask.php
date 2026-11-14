<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use CattoPHP\Scheduler\ScheduledTask;
use DateTimeImmutable;

/**
 * Every night: the IP address of each opening of a tracking link older than the IP setting is removed, and each visitor
 * not seen for longer than the history setting is made anonymous (token and account gone); the touches and the totals
 * stay.
 */
final class MarketingTidyUpTask implements ScheduledTask
{
    public function __construct(private readonly Touches $touches, private readonly Visitors $visitors, private readonly MarketingSettings $settings)
    {
    }

    public function key(): string
    {
        return 'marketing_tidy_up';
    }

    public function label(): string
    {
        return 'Campaign Marketing Manager tidy-up';
    }

    public function description(): string
    {
        return 'Every night: removes the IP addresses of tracking link openings older than the Campaign Marketing Manager keeps them, and makes anonymous the visitors not seen for longer than their history is kept.';
    }

    public function interval(): int
    {
        return 86400;
    }

    public function runsAt(): string
    {
        return '03:20';
    }

    public function overdueAfter(): int
    {
        return 2 * 86400;
    }

    public function run(DateTimeImmutable $now): string
    {
        $addresses = $this->touches->removeAddressesBefore($this->settings->ipKept()->before($now));
        $visitors = $this->visitors->anonymiseBefore($this->settings->historyKept()->before($now), $now);

        return sprintf('%d IP %s removed, %d %s made anonymous.', $addresses, $addresses === 1 ? 'address' : 'addresses', $visitors, $visitors === 1 ? 'visitor' : 'visitors');
    }
}
