<?php

declare(strict_types=1);

namespace CattoPHP\Access;

/** A role and the permissions it grants. ADMIN grants every permission and lists none. */
final class Role
{
    /** @param list<string> $permissions */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $description,
        public readonly array $permissions,
    ) {
    }

    public function isAdministrator(): bool
    {
        return $this->key === Roles::ADMIN;
    }
}
