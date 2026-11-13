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

    /** Gives a role a new key, its permissions and holders going with it. */
    public function renameRole(string $from, string $to): void;

    /** @return list<string> the keys of the roles an account holds */
    public function rolesOf(string $accountId): array;

    /** @return list<string> the accounts holding a role */
    public function holders(string $roleKey): array;

    public function grant(string $accountId, string $roleKey, DateTimeImmutable $at, ?string $by): void;

    public function revoke(string $accountId, string $roleKey): void;

    /**
     * Takes a role from an account unless it is the role's last holder, as one step no other change to the role's holders
     * can come between (the database's: its holders locked while it counts them). True when it was taken away; false when
     * the account was its last holder, and nothing changed.
     */
    public function revokeUnlessLast(string $accountId, string $roleKey): bool;

    /**
     * The accounts holding a role, locked against any change until the transaction around the call ends (the database's:
     * two requests that would each take away one of the last two holders then wait for each other, and the second counts
     * one). Outside a transaction, or with no database, as holders().
     *
     * @return list<string>
     */
    public function lockHolders(string $roleKey): array;

    /**
     * An account being deleted: every role it holds goes, and the roles it gave others no longer say who gave them.
     * Returns how many roles it held.
     */
    public function forgetAccount(string $accountId): int;

    /** Whether roles can be changed here at all: false for an installation without a database. */
    public function isWritable(): bool;
}
