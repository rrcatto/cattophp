<?php

declare(strict_types=1);

namespace CattoPHP\Schema\Steps;

use CattoPHP\Schema\SqlStep;

/**
 * The marks people put on setup steps that need them (Setup\DbalSetupMarkStore): done or skipped, when, by whom and with
 * what note. A step the site checks by itself keeps nothing here.
 */
final class Step202611121400SetupSteps extends SqlStep
{
    public function id(): string
    {
        return '202611121400-setup-steps';
    }

    public function describe(): string
    {
        return 'Setup steps marked by a person';
    }

    protected function statements(): array
    {
        return [
            'CREATE TABLE cattophp_setup_steps (
                step_key text PRIMARY KEY CHECK (step_key ~ \'^[a-z][a-z0-9_-]{0,63}$\'),
                state text NOT NULL CHECK (state IN (\'done\', \'skipped\')),
                note text NOT NULL DEFAULT \'\' CHECK (char_length(note) <= 1000),
                marked_at timestamptz NOT NULL,
                marked_by text NULL
            )',
        ];
    }
}
