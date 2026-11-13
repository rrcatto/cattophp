<?php

declare(strict_types=1);

namespace CattoPHP\Account;

/**
 * Whatever keeps records of accounts, and forgets an account when the project deletes it: each of CattoPHP's modules
 * that does (switched on), and any of the project's own services that implements this (found by autoconfiguration).
 * AccountRecords::forget() calls each, in one transaction where the records are in the database.
 *
 * A record that is the account's alone goes; a record that stays, because it is someone else's too or says what
 * happened, stops naming the account: its account emptied, an address kept there AccountRecords::FORGOTTEN_EMAIL, a name
 * or username left empty and a network address gone.
 */
interface ForgetsAccounts
{
    /**
     * Called before the activity log is changed, so a keeper may read what it knows the account by before its records go,
     * and give it as `words`: each is then no longer said anywhere in the log's entries (a Telegram username, an identity
     * check's session, an earlier address the project kept).
     *
     * @param string $account the account being deleted, by its id
     * @param string|null $email its address, where the project still knows it, for what was kept by the address alone
     * @return array{removed: array<string,int>, unnamed: array<string,int>, words?: list<string>} how many records of each
     *     kind went and how many stopped naming the account, by a plural name for them such as 'Telegram connections', and
     *     what else names the account in the activity log
     */
    public function forgetAccount(string $account, ?string $email): array;
}
