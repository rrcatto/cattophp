<?php

declare(strict_types=1);

namespace CattoPHP\Account\Records;

use CattoPHP\Account\ForgetsAccounts;
use CattoPHP\Search\PageOverrides;

/** The search engines module's part in forgetting an account: the words it gave the pages stay, by nobody named. */
final class SearchRecords implements ForgetsAccounts
{
    public function __construct(private readonly PageOverrides $pages)
    {
    }

    public function forgetAccount(string $account, ?string $email): array
    {
        return ['removed' => [], 'unnamed' => ['search pages' => $this->pages->forget($account)]];
    }
}
