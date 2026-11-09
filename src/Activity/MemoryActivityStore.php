<?php

declare(strict_types=1);

namespace CattoPHP\Activity;

/** The activity log held for one run: for tests. */
final class MemoryActivityStore implements ActivityStore
{
    /** @var list<ActivityEntry> */
    private array $entries = [];

    public function add(ActivityEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    public function recent(int $limit = 50, ?string $action = null, ?string $subjectType = null, ?string $subjectId = null, ?string $actorId = null): array
    {
        $found = [];
        foreach (array_reverse($this->entries) as $entry) {
            if (($action !== null && $entry->action !== $action) || ($subjectType !== null && $entry->subjectType !== $subjectType)
                || ($subjectId !== null && $entry->subjectId !== $subjectId) || ($actorId !== null && $entry->actorId !== $actorId)) {
                continue;
            }
            $found[] = $entry;
            if (count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }
}
