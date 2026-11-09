<?php

declare(strict_types=1);

namespace CattoPHP\Schema\Steps;

use CattoPHP\Schema\SqlStep;

/** The activity log: every change, who made it, from where and when. Rows are only ever added. */
final class Step202611091001Activity extends SqlStep
{
    public function id(): string
    {
        return '202611091001-activity';
    }

    public function describe(): string
    {
        return 'The activity log';
    }

    protected function statements(): array
    {
        return [
            'CREATE TABLE cattophp_activity (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                happened_at timestamptz NOT NULL,
                action text NOT NULL,
                actor_id text NULL,
                actor_name text NULL,
                subject_type text NULL,
                subject_id text NULL,
                details jsonb NOT NULL DEFAULT \'{}\'::jsonb,
                ip_address text NULL,
                request_reference text NULL
            )',
            'CREATE INDEX cattophp_activity_happened ON cattophp_activity (happened_at DESC, id DESC)',
            'CREATE INDEX cattophp_activity_subject ON cattophp_activity (subject_type, subject_id)',
            'CREATE INDEX cattophp_activity_actor ON cattophp_activity (actor_id)',
            'CREATE INDEX cattophp_activity_action ON cattophp_activity (action)',
        ];
    }
}
