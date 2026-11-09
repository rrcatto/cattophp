<?php

declare(strict_types=1);

namespace CattoPHP\Access;

use DateTimeImmutable;

/** Where roles, their permissions and who holds them are kept. */
interface RoleStore
{
    /** @return array<string,Role> by key */
    public function roles(): array;

    /** @param list<string> $permissions */
    public function saveRole(string $key, string $name, string $description, array $permissions, DateTimeImmutable $at): void;

    public function deleteRole(string $key): void;

    /** @return list<string> the keys of the roles an account holds */
    public function rolesOf(string $accountId): array;

    /** @return list<string> the accounts holding a role */
    public function holders(string $roleKey): array;

    public function grant(string $accountId, string $roleKey, DateTimeImmutable $at, ?string $by): void;

    public function revoke(string $accountId, string $roleKey): void;

    /** Whether roles can be changed here at all: false for an installation without a database. */
    public function isWritable(): bool;
}
