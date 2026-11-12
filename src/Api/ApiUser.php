<?php

declare(strict_types=1);

namespace CattoPHP\Api;

use CattoPHP\Activity\NamedAccount;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * An API identity in the shape Symfony's security layer needs. It adds nothing: the scope voter asks the identity's
 * scopes and CattoPHP's permission voter its account's roles. getRoles() is empty on purpose, so no rule is written once
 * as a ROLE_ and again as a permission.
 */
final class ApiUser implements UserInterface, NamedAccount
{
    public function __construct(public readonly ApiIdentity $identity)
    {
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return [];
    }

    public function getUserIdentifier(): string
    {
        return $this->identity->accountId !== '' ? $this->identity->accountId : 'api';
    }

    public function accountId(): string
    {
        return $this->identity->accountId;
    }

    public function accountName(): string
    {
        return $this->identity->name();
    }

    /** The token is never held beyond authentication, so there is nothing to erase. */
    public function eraseCredentials(): void
    {
    }
}
