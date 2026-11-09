<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

use CattoPHP\Activity\NamedAccount;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** A signed-in account, as Symfony's security system holds it. An administrator has ROLE_ADMIN. */
final class SignInUser implements UserInterface, EquatableInterface, NamedAccount
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly string $name,
        public readonly bool $administrator,
    ) {
    }

    public static function of(SignInAccount $account, bool $administrator): self
    {
        return new self($account->id, $account->email, $account->name, $administrator);
    }

    public function getUserIdentifier(): string
    {
        return $this->id === '' ? '-' : $this->id;
    }

    public function getRoles(): array
    {
        return $this->administrator ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function accountId(): string
    {
        return $this->id;
    }

    public function accountName(): string
    {
        return $this->name;
    }

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self && $user->id === $this->id && $user->email === $this->email && $user->administrator === $this->administrator;
    }
}
