<?php

declare(strict_types=1);

namespace CattoPHP\Account\Records;

use CattoPHP\Account\ForgetsAccounts;
use CattoPHP\Identity\IdentityChecks;

/**
 * Identity verification's part in forgetting an account: its identity checks and its allowed retries go; the checks it
 * revoked stay, no longer saying who did. The sessions of its checks are what the activity log's entries about them
 * still name (identity.verification_*), so they are given as words for the log to forget.
 */
final class IdentityRecords implements ForgetsAccounts
{
    public function __construct(private readonly IdentityChecks $checks)
    {
    }

    public function forgetAccount(string $account, ?string $email): array
    {
        $sessions = $this->checks->sessionsOf($account);
        $forgotten = $this->checks->forget($account);

        return [
            'removed' => ['identity checks' => $forgotten['checks'], 'identity retries allowed' => $forgotten['retries']],
            'unnamed' => ['revoked identity checks' => $forgotten['revoked']],
            'words' => $sessions,
        ];
    }
}
