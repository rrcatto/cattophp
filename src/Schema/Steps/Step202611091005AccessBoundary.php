<?php

declare(strict_types=1);

namespace CattoPHP\Schema\Steps;

use CattoPHP\Schema\SqlStep;

/**
 * A SYSTEM permission is the Administrator's alone, who holds every permission without a row: the database refuses one
 * written for any role, whatever writes it (Roles refuses it first).
 */
final class Step202611091005AccessBoundary extends SqlStep
{
    public function id(): string
    {
        return '202611091005-access-boundary';
    }

    public function describe(): string
    {
        return 'SYSTEM permissions kept for administrators';
    }

    protected function statements(): array
    {
        return [
            "ALTER TABLE cattophp_role_permissions ADD CONSTRAINT cattophp_role_permissions_not_system CHECK (permission NOT LIKE 'SYSTEM.%')",
        ];
    }
}
