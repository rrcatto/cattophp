<?php

declare(strict_types=1);

namespace CattoPHP\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
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

    /** @param iterable<ScheduledTask> $tasks */
    public function __construct(iterable $tasks, private readonly RunStore $runs, private readonly ClockInterface $clock, private readonly ?DateTimeZone $zone = null)
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
     * Runs every task that is due, or the named ones whether due or not.
     *
     * @param list<string>|null $only the keys of the tasks to run now
     * @return array<string,array{ok:bool,summary:string}> what each task that ran did, by its key
     */
    public function run(?array $only = null): array
    {
        if ($only !== null) {
            foreach ($only as $key) if (!isset($this->tasks[$key])) throw new InvalidArgumentException('There is no scheduled task ' . $key . '.');
        }
        $records = $this->runs->all();
        $results = [];
        foreach ($this->tasks as $key => $task) {
            if ($only !== null && !in_array($key, $only, true)) continue;
            if ($only === null) {
                $last = $records[$key]['last_started_at'] ?? null;
                if (!Schedule::isDue($task, $this->now(), $last === null ? null : new DateTimeImmutable($last), $this->zone ?? new DateTimeZone(date_default_timezone_get()))) continue;
            }
            $results[$key] = $this->runTask($task);
        }
        return $results;
    }

    /** @return array{ok:bool,summary:string} */
    private function runTask(ScheduledTask $task): array
    {
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

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
