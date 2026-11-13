<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel\App;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use CattoPHP\Installation\Installation;
use CattoPHP\SignIn\JoiningAccounts;
use CattoPHP\SignIn\SignInAccount;
use CattoPHP\SignIn\SignInAccounts;

/**
 * A small project's accounts for the kernel tests, held for the kernel's life: the installation's administrator and one
 * other person to begin with, and anyone else who may join by signing in for the first time.
 */
final class ExampleAccounts implements SignInAccounts, JoiningAccounts
{
    /** @var array<int|string,SignInAccount> by id */
    private array $accounts = [];

    public int $joined = 0;

    public function __construct(private readonly Installation $installation, private readonly ActivityLog $activity)
    {
        $this->accounts['1'] = new SignInAccount('1', 'owner@example.com', 'Olive Owner', true);
        $this->accounts['2'] = new SignInAccount('2', 'anna@example.com', 'Anna Example');
    }

    public function forEmail(string $email): ?SignInAccount
    {
        foreach ($this->accounts as $account) {
            if (strtolower($account->email) === strtolower(trim($email))) {
                return $account;
            }
        }

        return null;
    }

    public function forId(string $id): ?SignInAccount
    {
        return $this->accounts[$id] ?? null;
    }

    public function join(string $email): SignInAccount
    {
        $existing = $this->forEmail($email);
        if ($existing !== null) {
            return $existing;
        }
        $id = (string) (count($this->accounts) + 1);
        $administrator = strtolower($this->installation->administratorEmail()) === strtolower($email);
        $account = $this->accounts[$id] = new SignInAccount($id, strtolower($email), ucfirst(strstr($email, '@', true) ?: $email), $administrator);
        $this->joined++;
        $this->activity->record('account.created', ['email' => $account->email], 'account', $id, new Actor($id, $account->name));

        return $account;
    }
}
