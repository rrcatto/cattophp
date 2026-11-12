<?php

declare(strict_types=1);

namespace CattoPHP\Api;

use CattoPHP\Access\Roles;

/**
 * Who an API request acts for, once its token is accepted: the account, what the project says of it, its roles and the
 * permissions they give (the same as on the site's pages), and the scopes the token was issued for. A scope says what the
 * token is for, a permission what the person may do; an operation asks for both.
 */
final readonly class ApiIdentity
{
    /**
     * @param array<string,mixed> $account what the project reports of the account (ApiAccounts), its name among it
     * @param list<string> $roles
     * @param list<string> $permissions
     * @param list<string> $scopes
     * @param string|null $allScopes the scope that stands for every scope, when the project has one
     */
    public function __construct(
        public string $accountId,
        public array $account,
        public array $roles,
        public array $permissions,
        public array $scopes,
        public string $tokenPublicId,
        public ?string $allScopes = null,
    ) {
    }

    public function name(): string
    {
        return is_string($this->account['name'] ?? null) ? $this->account['name'] : $this->accountId;
    }

    public function hasRole(string $role): bool
    {
        return in_array(strtoupper($role), $this->roles, true);
    }

    public function hasPermission(string $permission): bool
    {
        return $this->hasRole(Roles::ADMIN) || in_array(strtoupper($permission), $this->permissions, true);
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true) || ($this->allScopes !== null && in_array($this->allScopes, $this->scopes, true));
    }
}
