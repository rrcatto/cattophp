<?php

declare(strict_types=1);

namespace CattoPHP\Admin;

use CattoPHP\SignIn\SignInAccount;
use CattoPHP\SignIn\SignInAccounts;

/**
 * The people a page of Administration names (a role's holders, who changed a setting, who did something in the activity
 * log), found by their account ids through the project's accounts, each once a page.
 */
final class AccountNames
{
    /** @var array<string,SignInAccount|null> */
    private array $found = [];

    public function __construct(private readonly ?SignInAccounts $accounts = null)
    {
    }

    public function account(?string $id): ?SignInAccount
    {
        if ($id === null || $id === '' || $this->accounts === null) {
            return null;
        }
        if (!array_key_exists($id, $this->found)) {
            $this->found[$id] = $this->accounts->forId($id);
        }

        return $this->found[$id];
    }

    /** An account's name: as the project names it now, else as it was recorded, else by its id. */
    public function name(string $id, ?string $recorded = null): string
    {
        $account = $this->account($id);
        if ($account !== null) {
            return $account->name;
        }

        return $recorded !== null && $recorded !== '' && $recorded !== $id ? $recorded : 'Account ' . $id . ', not found now';
    }
}
