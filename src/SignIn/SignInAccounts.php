<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

/**
 * The application's accounts, as sign-in needs them. The application provides this (its people, its users), and names
 * the service in its configuration (`cattophp.sign_in.accounts`). An account the application has disabled is not found.
 */
interface SignInAccounts
{
    public function forEmail(string $email): ?SignInAccount;

    public function forId(string $id): ?SignInAccount;
}
