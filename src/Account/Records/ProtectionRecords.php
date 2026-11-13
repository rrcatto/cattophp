<?php

declare(strict_types=1);

namespace CattoPHP\Account\Records;

use CattoPHP\Account\ForgetsAccounts;
use CattoPHP\Protection\Flags;
use CattoPHP\Protection\Lockouts;
use CattoPHP\Protection\RefusedEmails;
use CattoPHP\Protection\SiteLog;

/**
 * Site protection's part in forgetting an account: its requests stay in the Site Log, signed in as nobody, and the flags,
 * lockouts and refused addresses it decided on stay, no longer saying who did.
 */
final class ProtectionRecords implements ForgetsAccounts
{
    public function __construct(
        private readonly SiteLog $siteLog,
        private readonly Flags $flags,
        private readonly Lockouts $lockouts,
        private readonly RefusedEmails $refusedEmails,
    ) {
    }

    public function forgetAccount(string $account, ?string $email): array
    {
        return ['removed' => [], 'unnamed' => [
            'Site Log requests' => $this->siteLog->forget($account),
            'flags' => $this->flags->forget($account),
            'lockouts' => $this->lockouts->forget($account),
            'refused addresses' => $this->refusedEmails->forget($account),
        ]];
    }
}
