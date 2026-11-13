<?php

declare(strict_types=1);

namespace CattoPHP\Activity;

use DateTimeImmutable;

/** The activity log held for one run: for tests. */
final class MemoryActivityStore implements ActivityStore
{
    /** @var list<ActivityEntry> */
    private array $entries = [];

    public function add(ActivityEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    public function recent(int $limit = 50, ?string $action = null, ?string $subjectType = null, ?string $subjectId = null, ?string $actorId = null, int $offset = 0): array
    {
        $found = [];
        $skipped = 0;
        foreach (array_reverse($this->entries) as $entry) {
            if (!self::matches($entry, $action, $subjectType, $subjectId, $actorId)) {
                continue;
            }
            if ($skipped < $offset) {
                ++$skipped;
                continue;
            }
            $found[] = $entry;
            if (count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }

    public function count(?string $action = null, ?string $subjectType = null, ?string $subjectId = null, ?string $actorId = null): int
    {
        return count(array_filter($this->entries, static fn (ActivityEntry $entry): bool => self::matches($entry, $action, $subjectType, $subjectId, $actorId)));
    }

    public function actions(): array
    {
        $actions = array_values(array_unique(array_map(static fn (ActivityEntry $entry): string => $entry->action, $this->entries)));
        sort($actions);

        return $actions;
    }

    private static function matches(ActivityEntry $entry, ?string $action, ?string $subjectType, ?string $subjectId, ?string $actorId): bool
    {
        return ($action === null || $entry->action === $action) && ($subjectType === null || $entry->subjectType === $subjectType)
            && ($subjectId === null || $entry->subjectId === $subjectId) && ($actorId === null || $entry->actorId === $actorId);
    }

    public function removeBefore(DateTimeImmutable $before): int
    {
        $kept = array_values(array_filter($this->entries, static fn (ActivityEntry $entry): bool => $entry->at >= $before));
        $removed = count($this->entries) - count($kept);
        $this->entries = $kept;

        return $removed;
    }

    public function forgetAccount(string $accountId, string $name, array $words = []): int
    {
        $changed = 0;
        foreach ($this->entries as $number => $entry) {
            $forgotten = ActivityEntry::forgetting($entry, $accountId, $name, $words);
            if ($forgotten !== $entry) {
                $this->entries[$number] = $forgotten;
                ++$changed;
            }
        }

        return $changed;
    }
}
