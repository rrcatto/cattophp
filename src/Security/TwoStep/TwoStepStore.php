<?php

declare(strict_types=1);

namespace CattoPHP\Security\TwoStep;

/** Where each account's two-step sign-in is kept. */
interface TwoStepStore
{
    public function find(string $accountId): ?TwoStepRecord;

    /**
     * Reads the account's record and saves what $change returns, holding a lock in between, so two sign-ins at the same
     * moment cannot both use one code. $change returns null to leave it as it was.
     *
     * @param callable(?TwoStepRecord): ?TwoStepRecord $change
     */
    public function change(string $accountId, callable $change): void;

    public function forget(string $accountId): void;
}
