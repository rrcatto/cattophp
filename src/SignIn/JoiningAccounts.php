<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

/**
 * For a project where people join by signing in for the first time. The service named as the sign-in's accounts
 * (`cattophp.sign_in.accounts`) implements this beside SignInAccounts. An address with no account that may join
 * (SignInRules::mayJoin: the installation's administrator, APP_ADMIN_EMAIL, always; anyone where AUTH_SIGNIN is
 * EVERYONE; else the listed addresses) is sent a sign-in link like anyone else, and nothing is kept for it but the link.
 * When the link is used, which proves the address is theirs, join() makes the account. The sign-in page says the same to
 * every address, so nobody learns who has an account or who may join.
 */
interface JoiningAccounts
{
    /**
     * The account for an address whose sign-in link has just been used: a new one, made now (and recorded in the activity
     * log as the project records a new account), or the one it already has (two links used at once make one account).
     * Null when it may not have one after all. CattoPHP has checked SignInRules::mayJoin() just before.
     */
    public function join(string $email): ?SignInAccount;
}
