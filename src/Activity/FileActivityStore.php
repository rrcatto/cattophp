<?php

declare(strict_types=1);

namespace CattoPHP\Activity;

use DateTimeImmutable;
use RuntimeException;

/**
 * The activity log of an installation without a database: one line of JSON per entry, appended to a file only its own
 * account can read. Reading it back reads the newest part of the file.
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
        $path = $this->path();
        $new = !is_file($path);
        if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('The activity log could not be written.');
        }
        if ($new) {
            chmod($path, 0600);
        }
    }

    public function recent(int $limit = 50, ?string $action = null, ?string $subjectType = null, ?string $subjectId = null, ?string $actorId = null): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return [];
        }
        $size = (int) filesize($path);
        $text = (string) file_get_contents($path, false, null, max(0, $size - self::READ_BACK));
        $entries = [];
        foreach (array_reverse(explode("\n", trim($text))) as $line) {
            $row = json_decode($line, true);
            if (!is_array($row) || !is_string($row['action'] ?? null) || !is_string($row['at'] ?? null)) {
                continue;
            }
            $entry = new ActivityEntry(
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
            if (($action !== null && $entry->action !== $action) || ($subjectType !== null && $entry->subjectType !== $subjectType)
                || ($subjectId !== null && $entry->subjectId !== $subjectId) || ($actorId !== null && $entry->actorId !== $actorId)) {
                continue;
            }
            $entries[] = $entry;
            if (count($entries) >= $limit) {
                break;
            }
        }

        return $entries;
    }

    /**
     * Rewrites the file without the entries from before the moment, in place and under the lock every entry is added
     * under, so an entry added meanwhile waits and is never lost. A line that cannot be read, so cannot be dated, is kept.
     */
    public function removeBefore(DateTimeImmutable $before): int
    {
        $path = $this->path();
        if (!is_file($path)) {
            return 0;
        }
        $handle = fopen($path, 'r+');
        if ($handle === false) {
            throw new RuntimeException('The activity log could not be opened.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('The activity log could not be locked.');
            }
            $kept = [];
            $removed = 0;
            while (($line = fgets($handle)) !== false) {
                $row = json_decode(trim($line), true);
                if (is_array($row) && is_string($row['at'] ?? null) && new DateTimeImmutable($row['at']) < $before) {
                    ++$removed;
                    continue;
                }
                if (trim($line) !== '') {
                    $kept[] = rtrim($line, "\n") . "\n";
                }
            }
            if ($removed > 0) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, implode('', $kept));
                fflush($handle);
            }
            flock($handle, LOCK_UN);

            return $removed;
        } finally {
            fclose($handle);
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
