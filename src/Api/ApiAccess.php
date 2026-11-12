<?php

declare(strict_types=1);

namespace CattoPHP\Api;

/**
 * An operation's two questions about an API identity, for code that is not a route (a tool server, a job): does the token
 * carry the scope, and does the account have the permission, as the site's own pages ask it.
 */
final class ApiAccess
{
    /** @throws ApiAccessDenied */
    public function requireScope(ApiIdentity $identity, string $scope): void
    {
        if (!$identity->hasScope($scope)) {
            throw new ApiAccessDenied('The API token does not grant the required scope.');
        }
    }

    /** @throws ApiAccessDenied */
    public function requirePermission(ApiIdentity $identity, string $permission): void
    {
        if (!$identity->hasPermission($permission)) {
            throw new ApiAccessDenied('The authenticated account does not have the required permission.');
        }
    }

    /** @throws ApiAccessDenied */
    public function requireBusinessAccess(ApiIdentity $identity, string $scope, string $permission): void
    {
        $this->requireScope($identity, $scope);
        $this->requirePermission($identity, $permission);
    }
}
