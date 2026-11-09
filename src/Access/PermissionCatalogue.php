<?php

declare(strict_types=1);

namespace CattoPHP\Access;

use InvalidArgumentException;

/** Every permission there is. One that is not in it is never granted, so a mistyped key is refused, not allowed. */
final class PermissionCatalogue
{
    /** @var array<string,Permission> */
    private array $permissions = [];

    /** @param iterable<PermissionProvider> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            foreach ($provider->permissions() as $permission) {
                if (isset($this->permissions[$permission->key])) {
                    throw new InvalidArgumentException(sprintf('The permission %s is named twice.', $permission->key));
                }
                $this->permissions[$permission->key] = $permission;
            }
        }
        ksort($this->permissions);
    }

    /** @return array<string,Permission> by key */
    public function all(): array
    {
        return $this->permissions;
    }

    public function has(string $key): bool
    {
        return isset($this->permissions[$key]);
    }

    public function get(string $key): Permission
    {
        return $this->permissions[$key] ?? throw new InvalidArgumentException(sprintf('There is no permission %s.', $key));
    }

    /** @return array<string,list<Permission>> by group, in order */
    public function grouped(bool $withAdministratorsOnly = true): array
    {
        $grouped = [];
        foreach ($this->permissions as $permission) {
            if ($withAdministratorsOnly || !$permission->isAdministratorsOnly()) {
                $grouped[$permission->group][] = $permission;
            }
        }
        ksort($grouped);

        return $grouped;
    }
}
