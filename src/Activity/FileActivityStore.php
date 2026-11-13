<?php

declare(strict_types=1);

namespace CattoPHP\Activity;

use DateTimeImmutable;
use RuntimeException;

/**
 * The activity log of an installation without a database: one line of JSON per entry, appended to a file only its own
 * account can read. Reading it back reads the newest part of the file (the last 2 MB): what is older than that is still
 * kept but is not listed, counted or filtered by. Every change is made under one lock (activity.log.lock beside it), and
 * a rewrite (removing old entries, forgetting an account) writes the new log whole before it takes the old one's place.
 */
final class FileActivityStore implements ActivityStore
{
    /** How much of the end of the file is read for the newest entries. */
    private const READ_BACK = 2_000_000;

    public function __construct(private readonly string $folder)
    {
    }

    public function add(ActivityEntry $entry): void
    {
        if (!is_dir($this->folder) && !mkdir($this->folder, 0700, true) && !is_dir($this->folder)) {
            throw new RuntimeException('The activity log\'s folder could not be made.');
        }
        $line = json_encode([
            'at' => $entry->at->format('Y-m-d\TH:i:s.uP'),
            'action' => $entry->action,
            'actor_id' => $entry->actorId,
            'actor_name' => $entry->actorName,
            'subject_type' => $entry->subjectType,
            'subject_id' => $entry->subjectId,
            'details' => $entry->details === [] ? new \stdClass() : $entry->details,
            'ip_address' => $entry->ipAddress,
            'request_reference' => $entry->requestReference,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $this->locked(function () use ($line): void {
            $path = $this->path();
            $new = !is_file($path);
            if (@file_put_contents($path, $line, FILE_APPEND) !== strlen($line)) {
                throw new RuntimeException('The activity log could not be written.');
            }
            if ($new) {
                chmod($path, 0600);
            }
        });
    }

    public function recent(int $limit = 50, ?string $action = null, ?string $subjectType = null, ?string $subjectId = null, ?string $actorId = null, int $offset = 0): array
    {
        $entries = [];
        $skipped = 0;
        foreach ($this->newestFirst() as $entry) {
            if (!self::matches($entry, $action, $subjectType, $subjectId, $actorId)) {
                continue;
            }
            if ($skipped < $offset) {
                ++$skipped;
                continue;
            }
            $entries[] = $entry;
            if (count($entries) >= $limit) {
                break;
            }
        }

        return $entries;
    }

    public function count(?string $action = null, ?string $subjectType = null, ?string $subjectId = null, ?string $actorId = null): int
    {
        $count = 0;
        foreach ($this->newestFirst() as $entry) {
            if (self::matches($entry, $action, $subjectType, $subjectId, $actorId)) {
                ++$count;
            }
        }

        return $count;
    }

    public function actions(): array
    {
        $actions = [];
        foreach ($this->newestFirst() as $entry) {
            $actions[$entry->action] = true;
        }
        $actions = array_map('strval', array_keys($actions));
        sort($actions);

        return $actions;
    }

    /**
     * The entries in the newest part of the file, newest first; a line that cannot be read is passed over.
     *
     * @return iterable<ActivityEntry>
     */
    private function newestFirst(): iterable
    {
        $path = $this->path();
        if (!is_file($path)) {
            return;
        }
        $size = (int) filesize($path);
        $text = (string) file_get_contents($path, false, null, max(0, $size - self::READ_BACK));
        foreach (array_reverse(explode("\n", trim($text))) as $line) {
            $row = json_decode($line, true);
            if (!is_array($row) || !is_string($row['action'] ?? null) || !is_string($row['at'] ?? null)) {
                continue;
            }
            yield new ActivityEntry(
                new DateTimeImmutable($row['at']),
                $row['action'],
                self::text($row['actor_id'] ?? null),
                self::text($row['actor_name'] ?? null),
                self::text($row['subject_type'] ?? null),
                self::text($row['subject_id'] ?? null),
                is_array($row['details'] ?? null) ? self::keyed($row['details']) : [],
                self::text($row['ip_address'] ?? null),
                self::text($row['request_reference'] ?? null),
            );
        }
    }

    private static function matches(ActivityEntry $entry, ?string $action, ?string $subjectType, ?string $subjectId, ?string $actorId): bool
    {
        return ($action === null || $entry->action === $action) && ($subjectType === null || $entry->subjectType === $subjectType)
            && ($subjectId === null || $entry->subjectId === $subjectId) && ($actorId === null || $entry->actorId === $actorId);
    }

    /**
     * Rewrites the file without the entries from before the moment (rewrite()), so an entry added meanwhile waits and is
     * never lost. A line that cannot be read, so cannot be dated, is kept.
     */
    public function removeBefore(DateTimeImmutable $before): int
    {
        return $this->rewrite(static function (string $line) use ($before): ?string {
            $row = json_decode(trim($line), true);

            return is_array($row) && is_string($row['at'] ?? null) && new DateTimeImmutable($row['at']) < $before ? null : $line;
        });
    }

    /**
     * Rewrites the file with an account being deleted no longer named (ActivityEntry::forgetting(): by it, about it, or
     * naming it in what it says), each such entry's network address gone too (rewrite()). A line that cannot be read is
     * kept as it is.
     */
    public function forgetAccount(string $accountId, string $name, array $words = []): int
    {
        return $this->rewrite(static function (string $line) use ($accountId, $name, $words): string {
            $row = json_decode(trim($line), true);
            if (!is_array($row) || !is_string($row['action'] ?? null) || !is_string($row['at'] ?? null)) {
                return $line;
            }
            $entry = new ActivityEntry(new DateTimeImmutable($row['at']), $row['action'], self::text($row['actor_id'] ?? null), self::text($row['actor_name'] ?? null),
                self::text($row['subject_type'] ?? null), self::text($row['subject_id'] ?? null), is_array($row['details'] ?? null) ? self::keyed($row['details']) : [],
                self::text($row['ip_address'] ?? null), self::text($row['request_reference'] ?? null));
            $forgotten = ActivityEntry::forgetting($entry, $accountId, $name, $words);
            if ($forgotten === $entry) {
                return $line;
            }
            $row['actor_id'] = $forgotten->actorId;
            $row['actor_name'] = $forgotten->actorName;
            $row['subject_id'] = $forgotten->subjectId;
            $row['details'] = $forgotten->details === [] ? new \stdClass() : $forgotten->details;
            $row['ip_address'] = $forgotten->ipAddress;

            return json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        });
    }

    /**
     * Rewrites the log a line at a time ($change gives the line, changed or as it was, or null to leave it out), under the
     * lock every entry is added under. The new log is written whole to a file beside it and only then put in its place,
     * so a write that fails (a full disk) leaves the log as it was, never cut short or empty. Returns how many lines
     * changed or went; with none, the file is not touched.
     *
     * @param callable(string):?string $change
     */
    private function rewrite(callable $change): int
    {
        if (!is_file($this->path())) {
            return 0;
        }

        return $this->locked(function () use ($change): int {
            $path = $this->path();
            $handle = fopen($path, 'r');
            if ($handle === false) {
                throw new RuntimeException('The activity log could not be opened.');
            }
            $lines = [];
            $changed = 0;
            try {
                while (($line = fgets($handle)) !== false) {
                    $line = rtrim($line, "\n");
                    if (trim($line) === '') {
                        continue;
                    }
                    $after = $change($line);
                    if ($after !== $line) {
                        ++$changed;
                    }
                    if ($after !== null) {
                        $lines[] = $after . "\n";
                    }
                }
            } finally {
                fclose($handle);
            }
            if ($changed === 0) {
                return 0;
            }
            $text = implode('', $lines);
            $temporary = $path . '.' . bin2hex(random_bytes(4));
            try {
                if (@file_put_contents($temporary, $text) !== strlen($text) || !@chmod($temporary, 0600) || !@rename($temporary, $path)) {
                    throw new RuntimeException('The activity log could not be rewritten, so it is as it was: is the disk full?');
                }
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }

            return $changed;
        });
    }

    /**
     * Runs $work under the lock every change to the log is made under: a file of its own beside the log, since a rewrite
     * puts a new file in the log's place and a lock on the old one would no longer hold anyone back.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function locked(callable $work): mixed
    {
        if (!is_dir($this->folder) && !mkdir($this->folder, 0700, true) && !is_dir($this->folder)) {
            throw new RuntimeException('The activity log\'s folder could not be made.');
        }
        $lock = fopen($this->path() . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('The activity log could not be locked.');
        }
        try {
            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function path(): string
    {
        return rtrim($this->folder, '/') . '/activity.log';
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param array<mixed> $details
     * @return array<string,mixed>
     */
    private static function keyed(array $details): array
    {
        $keyed = [];
        foreach ($details as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }
}
