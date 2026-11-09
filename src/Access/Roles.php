<?php

declare(strict_types=1);

namespace CattoPHP\Access;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use Psr\Clock\ClockInterface;

/**
 * Roles: making and changing them, giving them to accounts and taking them away, and what an account may do. ADMIN
 * holds every permission, now and as new ones are added; it cannot be deleted, nobody takes it from themselves, and
 * the last account holding it keeps it. A permission starting `SYSTEM.` is for ADMIN alone. Every change is in the
 * activity log.
 */
final class Roles
{
    public const ADMIN = 'ADMIN';

    /** @var array<string,Role>|null */
    private ?array $roles = null;

    /** @var array<string,list<string>> account => role keys, read once each */
    private array $held = [];

    public function __construct(
        private readonly RoleStore $store,
        private readonly PermissionCatalogue $catalogue,
        private readonly ActivityLog $activity,
        private readonly ClockInterface $clock,
    ) {
    }

    public function catalogue(): PermissionCatalogue
    {
        return $this->catalogue;
    }

    public function canChange(): bool
    {
        return $this->store->isWritable();
    }

    /** @return array<string,Role> by key */
    public function all(): array
    {
        return $this->roles ??= $this->store->roles();
    }

    public function get(string $key): Role
    {
        return $this->all()[$key] ?? throw new AccessRefused('There is no such role.');
    }

    /** @return list<string> the keys of the roles an account holds */
    public function rolesOf(string $accountId): array
    {
        return $this->held[$accountId] ??= $this->store->rolesOf($accountId);
    }

    public function isAdministrator(string $accountId): bool
    {
        return in_array(self::ADMIN, $this->rolesOf($accountId), true);
    }

    /** @return list<string> every permission the account has, ADMIN's being all of them */
    public function permissionsOf(string $accountId): array
    {
        if ($this->isAdministrator($accountId)) {
            return array_keys($this->catalogue->all());
        }
        $permissions = [];
        foreach ($this->rolesOf($accountId) as $key) {
            foreach ($this->all()[$key]->permissions ?? [] as $permission) {
                if ($this->catalogue->has($permission) && !$this->catalogue->get($permission)->isAdministratorsOnly()) {
                    $permissions[$permission] = true;
                }
            }
        }
        $keys = array_keys($permissions);
        sort($keys);

        return $keys;
    }

    public function may(string $accountId, string $permission): bool
    {
        return $this->catalogue->has($permission) && in_array($permission, $this->permissionsOf($accountId), true);
    }

    /** @return list<string> the accounts holding a role */
    public function holders(string $roleKey): array
    {
        return $this->store->holders($roleKey);
    }

    /**
     * Makes a role, or changes one, with the permissions it grants.
     *
     * @param list<string> $permissions
     */
    public function save(string $key, string $name, string $description, array $permissions, ?Actor $actor = null): void
    {
        $key = strtoupper(trim($key));
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $description = trim($description);
        if ($key === self::ADMIN) {
            throw new AccessRefused('The Administrator role holds every permission and cannot be changed.');
        }
        if (preg_match('/^[A-Z][A-Z0-9_]{1,39}$/', $key) !== 1) {
            throw new AccessRefused('A role\'s key is 2 to 40 capital letters, digits and underscores, starting with a letter, such as EDITOR.');
        }
        if ($name === '' || mb_strlen($name) > 80) {
            throw new AccessRefused('A role needs a name of up to 80 characters.');
        }
        foreach ($this->all() as $other) {
            if ($other->key !== $key && mb_strtolower($other->name) === mb_strtolower($name)) {
                throw new AccessRefused(sprintf('Another role is already called %s.', $other->name));
            }
        }
        $permissions = array_values(array_unique($permissions));
        sort($permissions);
        foreach ($permissions as $permission) {
            if (!$this->catalogue->has($permission)) {
                throw new AccessRefused(sprintf('There is no permission %s.', $permission));
            }
            if ($this->catalogue->get($permission)->isAdministratorsOnly()) {
                throw new AccessRefused(sprintf('%s is for administrators alone: no other role can hold it.', $permission));
            }
        }
        $before = $this->all()[$key] ?? null;
        $this->store->saveRole($key, $name, $description, $permissions, $this->clock->now());
        $this->roles = null;
        $this->activity->record($before === null ? 'access.role_made' : 'access.role_changed', [
            'name' => $name,
            'description' => $description,
            'permissions' => $permissions,
            'old_name' => $before?->name,
            'old_description' => $before?->description,
            'old_permissions' => $before?->permissions,
        ], 'role', $key, $actor);
    }

    /** Gives a role a new key, its permissions and holders going with it. The Administrator role keeps its own. */
    public function rename(string $from, string $to, ?Actor $actor = null): void
    {
        $role = $this->get($from);
        $to = strtoupper(trim($to));
        if ($role->isAdministrator() || $to === self::ADMIN) {
            throw new AccessRefused('The Administrator role keeps its key.');
        }
        if ($to === $from) {
            return;
        }
        if (preg_match('/^[A-Z][A-Z0-9_]{1,39}$/', $to) !== 1) {
            throw new AccessRefused('A role\'s key is 2 to 40 capital letters, digits and underscores, starting with a letter, such as EDITOR.');
        }
        if (isset($this->all()[$to])) {
            throw new AccessRefused(sprintf('Another role already has the key %s.', $to));
        }
        $this->store->renameRole($from, $to);
        $this->refresh();
        $this->activity->record('access.role_renamed', ['name' => $role->name, 'old_key' => $from, 'key' => $to], 'role', $to, $actor);
    }

    /** Forgets what was read, so the next question reads the store again: for a process that lives on after a change elsewhere. */
    public function refresh(): void
    {
        $this->roles = null;
        $this->held = [];
    }

    public function delete(string $key, ?Actor $actor = null): void
    {
        $role = $this->get($key);
        if ($role->isAdministrator()) {
            throw new AccessRefused('The Administrator role cannot be deleted.');
        }
        $this->store->deleteRole($key);
        $this->roles = null;
        $this->held = [];
        $this->activity->record('access.role_deleted', ['name' => $role->name, 'permissions' => $role->permissions], 'role', $key, $actor);
    }

    public function grant(string $accountId, string $roleKey, ?Actor $actor = null): void
    {
        $role = $this->get($roleKey);
        if (in_array($roleKey, $this->rolesOf($accountId), true)) {
            return;
        }
        $actor ??= $this->activity->signedIn();
        $this->store->grant($accountId, $roleKey, $this->clock->now(), $actor?->id);
        unset($this->held[$accountId]);
        $this->activity->record('access.role_given', ['role' => $roleKey, 'name' => $role->name], 'account', $accountId, $actor);
    }

    public function revoke(string $accountId, string $roleKey, ?Actor $actor = null): void
    {
        $role = $this->get($roleKey);
        if (!in_array($roleKey, $this->rolesOf($accountId), true)) {
            return;
        }
        $actor ??= $this->activity->signedIn();
        if ($role->isAdministrator()) {
            if ($actor !== null && $actor->id === $accountId) {
                throw new AccessRefused('You cannot take the Administrator role from yourself: another administrator can.');
            }
            if (count($this->holders(self::ADMIN)) <= 1) {
                throw new AccessRefused('This is the last administrator: give the role to someone else first.');
            }
        }
        $this->store->revoke($accountId, $roleKey);
        unset($this->held[$accountId]);
        $this->activity->record('access.role_taken', ['role' => $roleKey, 'name' => $role->name], 'account', $accountId, $actor);
    }
}
