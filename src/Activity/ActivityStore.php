<?php

declare(strict_types=1);

namespace CattoPHP\Activity;

use DateTimeImmutable;

/** Where the activity log is kept. Entries are only ever added. */
interface ActivityStore
{
    public function add(ActivityEntry $entry): void;

    /**
     * The newest entries first, narrowed by what is given.
     *
     * @return list<ActivityEntry>
     */
    public function recent(int $limit = 50, ?string $action = null, ?string $subjectType = null, ?string $subjectId = null, ?string $actorId = null): array;

    /** Removes every entry from before a moment, for a log kept only so long; returns how many went. */
    public function removeBefore(DateTimeImmutable $before): int;
}
