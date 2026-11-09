<?php

declare(strict_types=1);

namespace CattoPHP\Access;

use DateTimeImmutable;
use RuntimeException;

/**
 * Roles held for one run (for tests), or, not writable, the store of an installation without a database: there
 * nobody holds a role, and administrators are the accounts the application says are administrators.
 */
final class MemoryRoleStore implements RoleStore
{
    /** @var array<string,Role> */
    private array $roles = [];

    /** @var array<string,array<string,true>> account => role keys */
    private array $held = [];

    public function __construct(private readonly bool $writable = true)
    {
        $this->roles[Roles::ADMIN] = new Role(Roles::ADMIN, 'Administrator', 'Holds every permission, now and as new ones are added.', []);
    }

    public function roles(): array
    {
        return $this->roles;
    }

    public function saveRole(string $key, string $name, string $description, array $permissions, DateTimeImmutable $at): void
    {
        $this->writable();
        $this->roles[$key] = new Role($key, $name, $description, array_values(array_unique($permissions)));
    }

    public function deleteRole(string $key): void
    {
        $this->writable();
        unset($this->roles[$key]);
        foreach ($this->held as $account => $keys) {
            unset($this->held[$account][$key]);
        }
    }

    public function renameRole(string $from, string $to): void
    {
        $this->writable();
        $role = $this->roles[$from];
        unset($this->roles[$from]);
        $this->roles[$to] = new Role($to, $role->name, $role->description, $role->permissions);
        foreach ($this->held as $account => $keys) {
            if (isset($keys[$from])) {
                unset($this->held[$account][$from]);
                $this->held[$account][$to] = true;
            }
        }
    }

    public function rolesOf(string $accountId): array
    {
        $keys = array_keys($this->held[$accountId] ?? []);
        sort($keys);

        return $keys;
    }

    public function holders(string $roleKey): array
    {
        $accounts = [];
        foreach ($this->held as $account => $keys) {
            if (isset($keys[$roleKey])) {
                $accounts[] = (string) $account;
            }
        }
        sort($accounts);

        return $accounts;
    }

    public function grant(string $accountId, string $roleKey, DateTimeImmutable $at, ?string $by): void
    {
        $this->writable();
        $this->held[$accountId][$roleKey] = true;
    }

    public function revoke(string $accountId, string $roleKey): void
    {
        $this->writable();
        unset($this->held[$accountId][$roleKey]);
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    private function writable(): void
    {
        if (!$this->writable) {
            throw new RuntimeException('Roles are kept in the database, and this installation has none.');
        }
    }
}
