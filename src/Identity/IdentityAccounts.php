<?php

declare(strict_types=1);

namespace CattoPHP\Identity;

/**
 * What Didit is told of an account: its reference as vendor_data, so a check is easy to find in Didit's console. A
 * project gives its own (`identity.accounts`); without one, the account's id is the reference.
 */
interface IdentityAccounts
{
    public function reference(string $account): string;
}
