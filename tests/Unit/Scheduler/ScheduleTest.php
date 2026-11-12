<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Scheduler;

use CattoPHP\Scheduler\Schedule;
use CattoPHP\Tests\Support\ExampleTask;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/** When a task is due and when it has stopped, as cattolms's scheduler decided it (ScheduledJob::isDue). */
final class ScheduleTest extends TestCase
{
    private DateTimeZone $zone;

    protected function setUp(): void
    {
        $this->zone = new DateTimeZone('Africa/Johannesburg');
    }

    public function testATaskThatHasNeverRunIsDue(): void
    {
        self::assertTrue(Schedule::isDue(new ExampleTask(), new DateTimeImmutable('2026-11-12 10:00:00+02:00'), null, $this->zone));
        self::assertTrue(Schedule::isDue(new ExampleTask(at: '00:00', interval: 86400), new DateTimeImmutable('2026-11-12 10:00:00+02:00'), null, $this->zone));
    }

    public function testAnIntervalTaskIsDueAfterItsIntervalWithAFewSecondsSlack(): void
    {
        $task = new ExampleTask(interval: 60);
        $last = new DateTimeImmutable('2026-11-12 10:00:00+02:00');
        self::assertFalse(Schedule::isDue($task, $last->modify('+30 seconds'), $last, $this->zone));
        self::assertTrue(Schedule::isDue($task, $last->modify('+56 seconds'), $last, $this->zone), 'Five seconds of slack: a run a moment late last time is not skipped this time.');
        self::assertFalse(Schedule::isDue($task, $last->modify('+54 seconds'), $last, $this->zone));
    }

    public function testATimeOfDayTaskRunsOnceAfterThatTimeEvenWhenTheSchedulerWasStopped(): void
    {
        $task = new ExampleTask(key: 'log_archive', interval: 86400, at: '00:00');
        $yesterday = new DateTimeImmutable('2026-11-11 00:00:30+02:00');
        self::assertFalse(Schedule::isDue($task, new DateTimeImmutable('2026-11-11 23:59:00+02:00'), $yesterday, $this->zone), 'Before midnight: not yet.');
        self::assertTrue(Schedule::isDue($task, new DateTimeImmutable('2026-11-12 00:00:10+02:00'), $yesterday, $this->zone), 'Just after midnight.');
        self::assertTrue(Schedule::isDue($task, new DateTimeImmutable('2026-11-12 09:15:00+02:00'), $yesterday, $this->zone), 'A scheduler stopped at midnight runs it when it starts again.');
        self::assertFalse(Schedule::isDue($task, new DateTimeImmutable('2026-11-12 09:16:00+02:00'), new DateTimeImmutable('2026-11-12 09:15:00+02:00'), $this->zone), 'Once a day.');
        self::assertTrue(Schedule::isDue($task, new DateTimeImmutable('2026-11-11 22:30:00+00:00'), $yesterday, $this->zone), 'The time of day is the site\'s: 22:30 in London is half past midnight here.');
    }

    public function testATaskIsOverdueAfterItsOwnTimeWithoutASuccess(): void
    {
        $task = new ExampleTask(overdue: 300);
        $now = new DateTimeImmutable('2026-11-12 10:00:00+02:00');
        self::assertTrue(Schedule::isOverdue($task, $now, null), 'Never succeeded.');
        self::assertFalse(Schedule::isOverdue($task, $now, $now->modify('-300 seconds')));
        self::assertTrue(Schedule::isOverdue($task, $now, $now->modify('-301 seconds')));
    }
}
