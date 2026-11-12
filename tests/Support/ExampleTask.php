<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use CattoPHP\Scheduler\ScheduledTask;
use DateTimeImmutable;
use RuntimeException;

/** A scheduled task for the tests: its timing as given, counting its runs, failing when asked. */
final class ExampleTask implements ScheduledTask
{
    public int $runs = 0;

    public function __construct(
        private readonly string $key = 'orders',
        private readonly int $interval = 60,
        private readonly ?string $at = null,
        private readonly int $overdue = 300,
        public ?string $failWith = null,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->key));
    }

    public function description(): string
    {
        return 'An example of background work.';
    }

    public function interval(): int
    {
        return $this->interval;
    }

    public function runsAt(): ?string
    {
        return $this->at;
    }

    public function overdueAfter(): int
    {
        return $this->overdue;
    }

    public function run(DateTimeImmutable $now): string
    {
        $this->runs++;
        if ($this->failWith !== null) throw new RuntimeException($this->failWith);
        return 'Ran at ' . $now->format('H:i') . '.';
    }
}
