<?php

declare(strict_types=1);

namespace CattoPHP\Schema\Steps;

use CattoPHP\Schema\SqlStep;

/**
 * Identity verification: where the connection to Didit stands (one row), from the last Check the connection: when it was
 * tried, whether Didit accepted the key and the workflow, the workflow's name and what went wrong.
 */
final class Step202611130100IdentityConnection extends SqlStep
{
    public function id(): string
    {
        return '202611130100-identity-connection';
    }

    public function describe(): string
    {
        return 'Identity verification: where the connection to Didit stands';
    }

    protected function statements(): array
    {
        return [
            'CREATE TABLE cattophp_identity_connection (
                id smallint PRIMARY KEY CHECK (id = 1),
                state jsonb NOT NULL,
                updated_at timestamptz NOT NULL DEFAULT now()
            )',
        ];
    }
}
