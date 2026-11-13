<?php

declare(strict_types=1);

namespace CattoPHP\Security\TwoStep;

/** Two-step sign-in held for one run: for tests. */
final class MemoryTwoStepStore implements TwoStepStore
{
    /** @var array<string,TwoStepRecord> */
    private array $records = [];

    public function find(string $accountId): ?TwoStepRecord
    {
        return $this->records[$accountId] ?? null;
    }

    public function change(string $accountId, callable $change): void
    {
        $after = $change($this->records[$accountId] ?? null);
        if ($after !== null) {
            $this->records[$accountId] = $after;
        }
    }

    public function forget(string $accountId): bool
    {
        $had = isset($this->records[$accountId]);
        unset($this->records[$accountId]);

        return $had;
    }
}
