<?php

declare(strict_types=1);

namespace CattoPHP\Account\Records;

use CattoPHP\Account\ForgetsAccounts;
use CattoPHP\Setup\SetupMarkStore;

/** The setup checklist's part in forgetting an account: the steps it marked stay marked, by nobody named. */
final class SetupRecords implements ForgetsAccounts
{
    public function __construct(private readonly SetupMarkStore $marks)
    {
    }

    public function forgetAccount(string $account, ?string $email): array
    {
        return ['removed' => [], 'unnamed' => ['setup marks' => $this->marks->forget($account)]];
    }
}
