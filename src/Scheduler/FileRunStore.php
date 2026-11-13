<?php

declare(strict_types=1);

namespace CattoPHP\Scheduler;

use DateTimeImmutable;
use RuntimeException;

/** The runs in one JSON file, for a project without a database. Each change reads, changes and writes it under a lock. */
final class FileRunStore implements RunStore
{
    public function __construct(private readonly string $path)
    {
    }

    public function started(string $key, DateTimeImmutable $at): void
    {
        $this->change(static function (array $records) use ($key, $at): array {
            $record = $records[$key] ?? self::blank();
            $record['last_started_at'] = $at->format(DATE_ATOM);
            $record['run_count']++;
            $records[$key] = $record;
            return $records;
        });
    }

    public function succeeded(string $key, DateTimeImmutable $at, string $summary): void
    {
        $this->change(static function (array $records) use ($key, $at, $summary): array {
            if (!isset($records[$key])) return $records;
            $records[$key]['last_finished_at'] = $records[$key]['last_succeeded_at'] = $at->format(DATE_ATOM);
            $records[$key]['last_summary'] = mb_substr($summary, 0, self::LONGEST);
            return $records;
        });
    }

    public function failed(string $key, DateTimeImmutable $at, string $error): void
    {
        $this->change(static function (array $records) use ($key, $at, $error): array {
            if (!isset($records[$key])) return $records;
            $records[$key]['last_finished_at'] = $records[$key]['last_failed_at'] = $at->format(DATE_ATOM);
            $records[$key]['last_error'] = mb_substr($error, 0, self::LONGEST);
            return $records;
        });
    }

    public function tried(string $key, DateTimeImmutable $at, bool $ok, string $summary): void
    {
        $this->change(static function (array $records) use ($key, $at, $ok, $summary): array {
            $record = $records[$key] ?? self::blank();
            $record['last_trial_at'] = $at->format(DATE_ATOM);
            $record['last_trial_ok'] = $ok;
            $record['last_trial_summary'] = mb_substr($summary, 0, self::LONGEST);
            $records[$key] = $record;
            return $records;
        });
    }

    public function all(): array
    {
        if (!is_file($this->path)) return [];
        $records = self::decode((string) file_get_contents($this->path));
        ksort($records);
        return $records;
    }

    /** @return array{last_started_at:?string,last_finished_at:?string,last_succeeded_at:?string,last_failed_at:?string,last_summary:string,last_error:string,run_count:int,last_trial_at:?string,last_trial_ok:?bool,last_trial_summary:string} */
    private static function blank(): array
    {
        return ['last_started_at' => null, 'last_finished_at' => null, 'last_succeeded_at' => null, 'last_failed_at' => null, 'last_summary' => '', 'last_error' => '', 'run_count' => 0,
            'last_trial_at' => null, 'last_trial_ok' => null, 'last_trial_summary' => ''];
    }

    /** @param callable(array<string,array{last_started_at:?string,last_finished_at:?string,last_succeeded_at:?string,last_failed_at:?string,last_summary:string,last_error:string,run_count:int,last_trial_at:?string,last_trial_ok:?bool,last_trial_summary:string}>):array<string,array{last_started_at:?string,last_finished_at:?string,last_succeeded_at:?string,last_failed_at:?string,last_summary:string,last_error:string,run_count:int,last_trial_at:?string,last_trial_ok:?bool,last_trial_summary:string}> $change */
    private function change(callable $change): void
    {
        $folder = dirname($this->path);
        if (!is_dir($folder) && !@mkdir($folder, 0700, true) && !is_dir($folder)) throw new RuntimeException('The folder for the scheduled jobs\' runs could not be made.');
        $new = !is_file($this->path);
        $handle = fopen($this->path, 'c+');
        if ($handle === false) throw new RuntimeException('The scheduled jobs\' runs could not be opened.');
        // Only the owner reads it: a failed task's error may name more than a page should show.
        if ($new) @chmod($this->path, 0600);
        try {
            flock($handle, LOCK_EX);
            $records = self::decode((string) stream_get_contents($handle));
            $json = json_encode($change($records), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $json . "\n");
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array<string,array{last_started_at:?string,last_finished_at:?string,last_succeeded_at:?string,last_failed_at:?string,last_summary:string,last_error:string,run_count:int,last_trial_at:?string,last_trial_ok:?bool,last_trial_summary:string}> */
    private static function decode(string $json): array
    {
        $data = json_decode($json, true);
        $records = [];
        foreach (is_array($data) ? $data : [] as $key => $row) {
            if (!is_string($key) || !is_array($row)) continue;
            $text = static fn(mixed $value): ?string => is_string($value) ? $value : null;
            $records[$key] = [
                'last_started_at' => $text($row['last_started_at'] ?? null), 'last_finished_at' => $text($row['last_finished_at'] ?? null),
                'last_succeeded_at' => $text($row['last_succeeded_at'] ?? null), 'last_failed_at' => $text($row['last_failed_at'] ?? null),
                'last_summary' => is_string($row['last_summary'] ?? null) ? $row['last_summary'] : '', 'last_error' => is_string($row['last_error'] ?? null) ? $row['last_error'] : '',
                'run_count' => is_int($row['run_count'] ?? null) ? $row['run_count'] : 0,
                'last_trial_at' => $text($row['last_trial_at'] ?? null), 'last_trial_ok' => is_bool($row['last_trial_ok'] ?? null) ? $row['last_trial_ok'] : null,
                'last_trial_summary' => is_string($row['last_trial_summary'] ?? null) ? $row['last_trial_summary'] : '',
            ];
        }
        return $records;
    }
}
