<?php

declare(strict_types=1);

namespace CattoPHP\Activity;

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
}
