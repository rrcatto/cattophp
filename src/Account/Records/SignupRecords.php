<?php

declare(strict_types=1);

namespace CattoPHP\Account\Records;

use CattoPHP\Account\ForgetsAccounts;
use CattoPHP\Signup\SignupAttempts;

/** The sign-up check's part in forgetting an account: the attempt that made it goes, with what the check found. */
final class SignupRecords implements ForgetsAccounts
{
    public function __construct(private readonly SignupAttempts $attempts)
    {
    }

    public function forgetAccount(string $account, ?string $email): array
    {
        return ['removed' => ['sign-up attempts' => $this->attempts->forget($account)], 'unnamed' => []];
    }
}
