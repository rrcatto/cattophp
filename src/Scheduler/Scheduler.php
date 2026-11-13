<?php

declare(strict_types=1);

namespace CattoPHP\Scheduler;

use CattoPHP\Runtime\RunMode;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Throwable;

/**
 * One scheduler for a project's background work: every due task, or the ones named, is run and its run recorded. A task
 * that fails is recorded with its error and the rest still run. The command (`cattophp:schedule:run`) calls it once, or
 * once a minute with --watch.
 */
final class Scheduler
{
    /** @var array<string,ScheduledTask> */
    private array $tasks = [];

    /** What a run as if it were another moment is refused with, anywhere but on a development site. */
    public const TRIALS_REFUSED = 'A task is run as if it were another moment only on a development site, and this site\'s APP_ENV is production. Try it on a development site.';

    /**
     * @param iterable<ScheduledTask> $tasks
     * @param bool $development whether this is a development site, where a task may be run as if it were another moment:
     *     the bundle gives true only for a kernel in debug whose environment is not production (nor Symfony's prod), and
     *     APP_ENV saying production refuses it whatever this says
     */
    public function __construct(iterable $tasks, private readonly RunStore $runs, private readonly ClockInterface $clock, private readonly ?DateTimeZone $zone = null, private readonly bool $development = false)
    {
        foreach ($tasks as $task) {
            $key = $task->key();
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1) throw new InvalidArgumentException('A scheduled task\'s key is lower-case letters, digits and underscores: "' . $key . '" is not.');
            if (isset($this->tasks[$key])) throw new InvalidArgumentException('Two scheduled tasks have the key ' . $key . '.');
            $this->tasks[$key] = $task;
        }
    }

    /** @return list<ScheduledTask> every task, in the order they were given */
    public function tasks(): array
    {
        return array_values($this->tasks);
    }

    public function task(string $key): ?ScheduledTask
    {
        return $this->tasks[$key] ?? null;
    }

    /**
     * Runs every task that is due, or the named ones whether due or not. Before each task it asks $stopping, and once that
     * says yes (the process was told to stop) it runs no more: the task under way finishes, and those not started stay due.
     *
     * $at runs the named tasks as if it were that moment (a development site's trial of a time-based task, such as a
     * reminder that is due in three days): each task's run() is given $at for now. A trial is never a run: it is kept apart
     * (RunStore::tried(), at the real time, with "As at <moment>: " before what the task said), and the task's runs (its
     * last start and success, how often it ran) stay as they were, so its schedule and whether it is overdue are not moved.
     * Only with named tasks, and only on a development site (trialRefused()).
     *
     * @param list<string>|null $only the keys of the tasks to run now
     * @param (Closure():bool)|null $stopping whether to stop before the next task
     * @param DateTimeImmutable|null $at the moment the named tasks are given as now
     * @return array<string,array{ok:bool,summary:string}> what each task that ran did, by its key
     */
    public function run(?array $only = null, ?Closure $stopping = null, ?DateTimeImmutable $at = null): array
    {
        if ($only !== null) {
            foreach ($only as $key) if (!isset($this->tasks[$key])) throw new InvalidArgumentException('There is no scheduled task ' . $key . '.');
        }
        if ($at !== null && $only === null) {
            throw new InvalidArgumentException('A task is run at another moment only when it is named.');
        }
        if ($at !== null && $this->trialRefused() !== null) {
            throw new RuntimeException($this->trialRefused());
        }
        $records = $this->runs->all();
        $results = [];
        foreach ($this->tasks as $key => $task) {
            if ($stopping !== null && $stopping()) break;
            if ($only !== null && !in_array($key, $only, true)) continue;
            if ($only === null) {
                $last = $records[$key]['last_started_at'] ?? null;
                if (!Schedule::isDue($task, $this->now(), $last === null ? null : new DateTimeImmutable($last), $this->zone())) continue;
            }
            $results[$key] = $this->runTask($task, $at);
        }
        return $results;
    }

    /**
     * A moment typed for --now, in the scheduler's time zone: a date and time ("2026-12-01 09:00"; a date alone is its
     * midnight), or a moment counted from the scheduler's clock ("+3 days", "tomorrow 09:00").
     *
     * @throws InvalidArgumentException when it is not one
     */
    public function moment(string $typed): DateTimeImmutable
    {
        $typed = trim($typed);
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $typed) === 1) {
                return new DateTimeImmutable($typed, $this->zone());
            }
            $moment = $typed === '' ? false : $this->now()->setTimezone($this->zone())->modify($typed);
        } catch (Exception) {
            $moment = false;
        }
        if ($moment === false) {
            throw new InvalidArgumentException('"' . $typed . '" is not a date and time.');
        }

        return $moment;
    }

    /**
     * Why a task may not be run as if it were another moment here, or null where it may: anywhere but a development site,
     * which is what the bundle says it is (a kernel in debug, not in production) and the process's own APP_ENV does not
     * contradict (RunMode::environment(), production refusing it whatever the kernel says).
     */
    public function trialRefused(): ?string
    {
        return !$this->development || RunMode::environment() === RunMode::PRODUCTION ? self::TRIALS_REFUSED : null;
    }

    /** The time zone the tasks' times of day are read in, and a moment typed for --now. */
    public function zone(): DateTimeZone
    {
        return $this->zone ?? new DateTimeZone(date_default_timezone_get());
    }

    /** @return array{ok:bool,summary:string} */
    private function runTask(ScheduledTask $task, ?DateTimeImmutable $at = null): array
    {
        if ($at !== null) {
            return $this->tryTask($task, $at);
        }
        $this->runs->started($task->key(), $this->now());
        try {
            $summary = $task->run($this->now());
        } catch (Throwable $failed) {
            $this->runs->failed($task->key(), $this->now(), $failed->getMessage());
            return ['ok' => false, 'summary' => $failed->getMessage()];
        }
        $this->runs->succeeded($task->key(), $this->now(), $summary);
        return ['ok' => true, 'summary' => $summary];
    }

    /**
     * A trial: the task given $at for now, kept apart from its runs.
     *
     * @return array{ok:bool,summary:string}
     */
    private function tryTask(ScheduledTask $task, DateTimeImmutable $at): array
    {
        $said = 'As at ' . $at->setTimezone($this->zone())->format('Y-m-d H:i') . ': ';
        try {
            $result = ['ok' => true, 'summary' => $said . $task->run($at)];
        } catch (Throwable $failed) {
            $result = ['ok' => false, 'summary' => $said . $failed->getMessage()];
        }
        $this->runs->tried($task->key(), $this->now(), $result['ok'], $result['summary']);
        return $result;
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
