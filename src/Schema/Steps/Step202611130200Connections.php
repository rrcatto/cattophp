<?php

declare(strict_types=1);

namespace CattoPHP\Schema\Steps;

use CattoPHP\Schema\SqlStep;

/**
 * Where each connection to an outside service stands (Settings\ServiceConnections), one row a connection by its name, in
 * place of a table for each service: Didit's row moves from cattophp_identity_connection (step
 * 202611130100-identity-connection) as `didit`, and that table goes. Identity verification and payments each need it.
 */
final class Step202611130200Connections extends SqlStep
{
    public function id(): string
    {
        return '202611130200-connections';
    }

    public function describe(): string
    {
        return 'Where each connection to an outside service stands';
    }

    protected function statements(): array
    {
        return [
            'CREATE TABLE cattophp_connections (
                name text PRIMARY KEY,
                state jsonb NOT NULL,
                updated_at timestamptz NOT NULL DEFAULT now()
            )',
            // Only where identity verification made the older table: payments alone never had it.
            "DO \$\$ BEGIN
                IF to_regclass('cattophp_identity_connection') IS NOT NULL THEN
                    INSERT INTO cattophp_connections (name, state, updated_at)
                    SELECT 'didit', state, updated_at FROM cattophp_identity_connection WHERE id = 1;
                END IF;
            END \$\$",
            'DROP TABLE IF EXISTS cattophp_identity_connection',
        ];
    }
}
