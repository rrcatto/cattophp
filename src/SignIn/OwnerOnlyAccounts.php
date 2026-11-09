<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

use CattoPHP\Installation\Installation;

/**
 * One account, the installation's administrator (APP_ADMIN_EMAIL): for a page only its owner uses. Every other address
 * is no account at all.
 */
final class OwnerOnlyAccounts implements SignInAccounts
{
    public const ID = 'owner';

    public function __construct(private readonly Installation $installation)
    {
    }

    public function forEmail(string $email): ?SignInAccount
    {
        $owner = mb_strtolower(trim($this->installation->administratorEmail()));
        if ($owner === '' || !hash_equals($owner, mb_strtolower(trim($email)))) {
            return null;
        }

        return $this->owner();
    }

    public function forId(string $id): ?SignInAccount
    {
        return $id === self::ID && trim($this->installation->administratorEmail()) !== '' ? $this->owner() : null;
    }

    private function owner(): SignInAccount
    {
        $email = trim($this->installation->administratorEmail());

        return new SignInAccount(self::ID, $email, $email, true);
    }
}
