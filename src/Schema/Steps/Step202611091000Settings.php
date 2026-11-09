<?php

declare(strict_types=1);

namespace CattoPHP\Schema\Steps;

use CattoPHP\Schema\SqlStep;

/** What the Settings page has changed: one row per setting it overrides, a secret encrypted. */
final class Step202611091000Settings extends SqlStep
{
    public function id(): string
    {
        return '202611091000-settings';
    }

    public function describe(): string
    {
        return 'Settings that override .env';
    }

    protected function statements(): array
    {
        return [
            'CREATE TABLE cattophp_settings (
                name text PRIMARY KEY,
                value text NOT NULL,
                secret boolean NOT NULL DEFAULT false,
                updated_at timestamptz NOT NULL,
                updated_by text NULL
            )',
        ];
    }
}
