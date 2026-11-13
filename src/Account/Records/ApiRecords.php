<?php

declare(strict_types=1);

namespace CattoPHP\Account\Records;

use CattoPHP\Account\ForgetsAccounts;
use CattoPHP\Api\ApiTokens;

/** The API's part in forgetting an account: every token issued to it goes; those it made for others stay, by nobody named. */
final class ApiRecords implements ForgetsAccounts
{
    public function __construct(private readonly ApiTokens $tokens)
    {
    }

    public function forgetAccount(string $account, ?string $email): array
    {
        $forgotten = $this->tokens->forget($account);

        return ['removed' => ['API tokens' => $forgotten['tokens']], 'unnamed' => ['API tokens made for others' => $forgotten['made']]];
    }
}
