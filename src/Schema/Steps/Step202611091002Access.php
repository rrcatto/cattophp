<?php

declare(strict_types=1);

namespace CattoPHP\Schema\Steps;

use CattoPHP\Access\Roles;
use CattoPHP\Schema\SqlStep;

/**
 * Roles, the permissions each grants and who holds them. ADMIN is made here and holds every permission by definition,
 * so it lists none.
 */
final class Step202611091002Access extends SqlStep
{
    public function id(): string
    {
        return '202611091002-access';
    }

    public function describe(): string
    {
        return 'Roles and permissions';
    }

    protected function statements(): array
    {
        return [
            'CREATE TABLE cattophp_roles (
                key text PRIMARY KEY CHECK (key ~ \'^[A-Z][A-Z0-9_]*$\'),
                name text NOT NULL,
                description text NOT NULL DEFAULT \'\',
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL
            )',
            'CREATE UNIQUE INDEX cattophp_roles_name ON cattophp_roles (lower(name))',
            'CREATE TABLE cattophp_role_permissions (
                role_key text NOT NULL REFERENCES cattophp_roles (key) ON DELETE CASCADE ON UPDATE CASCADE,
                permission text NOT NULL,
                PRIMARY KEY (role_key, permission)
            )',
            'CREATE TABLE cattophp_account_roles (
                account_id text NOT NULL,
                role_key text NOT NULL REFERENCES cattophp_roles (key) ON DELETE CASCADE ON UPDATE CASCADE,
                granted_at timestamptz NOT NULL,
                granted_by text NULL,
                PRIMARY KEY (account_id, role_key)
            )',
            'CREATE INDEX cattophp_account_roles_role ON cattophp_account_roles (role_key)',
            "INSERT INTO cattophp_roles (key, name, description, created_at, updated_at)
                VALUES ('" . Roles::ADMIN . "', 'Administrator', 'Holds every permission, now and as new ones are added.', now(), now())",
        ];
    }
}
