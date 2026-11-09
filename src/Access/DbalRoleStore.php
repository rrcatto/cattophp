<?php

declare(strict_types=1);

namespace CattoPHP\Access;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/** Roles in the database (`cattophp_roles`, `cattophp_role_permissions`, `cattophp_account_roles`). */
final class DbalRoleStore implements RoleStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function roles(): array
    {
        $permissions = [];
        foreach ($this->connection->fetchAllAssociative('SELECT role_key, permission FROM cattophp_role_permissions ORDER BY permission') as $row) {
            $permissions[(string) $row['role_key']][] = (string) $row['permission'];
        }
        $roles = [];
        foreach ($this->connection->fetchAllAssociative('SELECT key, name, description FROM cattophp_roles ORDER BY name') as $row) {
            $key = (string) $row['key'];
            $roles[$key] = new Role($key, (string) $row['name'], (string) $row['description'], $permissions[$key] ?? []);
        }

        return $roles;
    }

    public function saveRole(string $key, string $name, string $description, array $permissions, DateTimeImmutable $at): void
    {
        $this->connection->transactional(function (Connection $connection) use ($key, $name, $description, $permissions, $at): void {
            $connection->executeStatement(
                'INSERT INTO cattophp_roles (key, name, description, created_at, updated_at) VALUES (:key, :name, :description, :at, :at)
                 ON CONFLICT (key) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description, updated_at = EXCLUDED.updated_at',
                ['key' => $key, 'name' => $name, 'description' => $description, 'at' => $at->format('Y-m-d H:i:sP')],
            );
            $connection->executeStatement('DELETE FROM cattophp_role_permissions WHERE role_key = ?', [$key]);
            foreach (array_unique($permissions) as $permission) {
                $connection->insert('cattophp_role_permissions', ['role_key' => $key, 'permission' => $permission]);
            }
        });
    }

    public function deleteRole(string $key): void
    {
        $this->connection->executeStatement('DELETE FROM cattophp_roles WHERE key = ?', [$key]);
    }

    public function rolesOf(string $accountId): array
    {
        /** @var list<string> $keys */
        $keys = $this->connection->fetchFirstColumn('SELECT role_key FROM cattophp_account_roles WHERE account_id = ? ORDER BY role_key', [$accountId]);

        return $keys;
    }

    public function holders(string $roleKey): array
    {
        /** @var list<string> $accounts */
        $accounts = $this->connection->fetchFirstColumn('SELECT account_id FROM cattophp_account_roles WHERE role_key = ? ORDER BY account_id', [$roleKey]);

        return $accounts;
    }

    public function grant(string $accountId, string $roleKey, DateTimeImmutable $at, ?string $by): void
    {
        $this->connection->executeStatement(
            'INSERT INTO cattophp_account_roles (account_id, role_key, granted_at, granted_by) VALUES (?, ?, ?, ?) ON CONFLICT DO NOTHING',
            [$accountId, $roleKey, $at->format('Y-m-d H:i:sP'), $by],
        );
    }

    public function revoke(string $accountId, string $roleKey): void
    {
        $this->connection->executeStatement('DELETE FROM cattophp_account_roles WHERE account_id = ? AND role_key = ?', [$accountId, $roleKey]);
    }

    public function isWritable(): bool
    {
        return true;
    }
}
