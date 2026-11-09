<?php

declare(strict_types=1);

namespace CattoPHP\Schema\Steps;

use CattoPHP\Schema\SqlStep;

/**
 * One person's activity and one kind of activity, newest first, read without sorting a large log: each index holds the
 * order the log is read in.
 */
final class Step202611091004ActivityLookups extends SqlStep
{
    public function id(): string
    {
        return '202611091004-activity-lookups';
    }

    public function describe(): string
    {
        return 'Faster lookups in the activity log';
    }

    protected function statements(): array
    {
        return [
            'DROP INDEX cattophp_activity_actor',
            'DROP INDEX cattophp_activity_action',
            'CREATE INDEX cattophp_activity_actor ON cattophp_activity (actor_id, happened_at DESC, id DESC)',
            'CREATE INDEX cattophp_activity_action ON cattophp_activity (action, happened_at DESC, id DESC)',
        ];
    }
}
