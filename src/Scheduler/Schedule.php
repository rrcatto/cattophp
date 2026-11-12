<?php

declare(strict_types=1);

namespace CattoPHP\Scheduler;

use DateTimeImmutable;
use DateTimeZone;

/** Whether a task is due. Pure: it changes nothing. */
final class Schedule
{
    /** A task started this much early is still counted as on time, so a run a moment late is not skipped next time. */
    public const SLACK = 5;

    /**
     * A task that has never started is due. One with a time of day is due once that time has passed since it last
     * started, in the site's time zone, so a scheduler that was stopped at the time runs it when it starts again. Any
     * other is due when its interval has passed since it last started, less the slack.
     */
    public static function isDue(ScheduledTask $task, DateTimeImmutable $now, ?DateTimeImmutable $lastStarted, DateTimeZone $zone): bool
    {
        if ($lastStarted === null) return true;
        $at = $task->runsAt();
        if ($at === null) return $now->getTimestamp() - $lastStarted->getTimestamp() >= $task->interval() - self::SLACK;
        [$hour, $minute] = array_map('intval', explode(':', $at));
        $local = $now->setTimezone($zone);
        $mark = $local->setTime($hour, $minute);
        if ($mark > $local) $mark = $mark->modify('-1 day');
        return $lastStarted < $mark;
    }

    /** Whether a task has gone too long without a successful run. */
    public static function isOverdue(ScheduledTask $task, DateTimeImmutable $now, ?DateTimeImmutable $lastSucceeded): bool
    {
        return $lastSucceeded === null || $now->getTimestamp() - $lastSucceeded->getTimestamp() > $task->overdueAfter();
    }
}
