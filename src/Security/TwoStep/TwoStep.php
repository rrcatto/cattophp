<?php

declare(strict_types=1);

namespace CattoPHP\Security\TwoStep;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use CattoPHP\Security\KeyedHash;
use Psr\Clock\ClockInterface;

/**
 * Two-step sign-in for an account: setting it up (the first right code proves the phone holds the key, and only then is
 * it kept), checking a code (each step used once), recovery codes (each used once, kept only as keyed hashes) and
 * starting again. Whether an account must use it is the sign-in's business (SignIn\SignInRules).
 */
final class TwoStep
{
    public function __construct(
        private readonly TwoStepStore $store,
        private readonly KeyedHash $hash,
        private readonly ActivityLog $activity,
        private readonly ClockInterface $clock,
        private readonly bool $recoveryCodes = true,
    ) {
    }

    public function isSetUp(string $accountId): bool
    {
        return $this->store->find($accountId) !== null;
    }

    public function offersRecoveryCodes(): bool
    {
        return $this->recoveryCodes;
    }

    /**
     * Keeps $secret for the account if $code is right for it now. Returns the new recovery codes to show the person
     * once (none when the installation does not use them), or null when the code is wrong.
     *
     * @return list<string>|null
     */
    public function setUp(string $accountId, string $secret, string $code): ?array
    {
        $counter = OneTimeCode::matchingCounter($secret, $code, $this->clock->now()->getTimestamp());
        if ($counter === null) {
            return null;
        }
        $codes = $this->recoveryCodes ? RecoveryCodes::make() : [];
        $hashes = array_map(fn (string $recovery): string => RecoveryCodes::hash($this->hash, $accountId, $recovery), $codes);
        $this->store->change($accountId, static fn (?TwoStepRecord $before): TwoStepRecord => new TwoStepRecord($secret, $counter, $hashes));
        $this->activity->record('two_step.set_up', ['recovery_codes' => count($codes)], 'account', $accountId);

        return $codes;
    }

    /** Whether $typed is the account's code now, or one of its unused recovery codes; either is then used up. */
    public function check(string $accountId, string $typed): bool
    {
        $recovery = RecoveryCodes::normalise($typed);
        if ($recovery !== null) {
            return $this->useRecoveryCode($accountId, $recovery);
        }
        $right = false;
        $now = $this->clock->now()->getTimestamp();
        $this->store->change($accountId, static function (?TwoStepRecord $record) use ($typed, $now, &$right): ?TwoStepRecord {
            if ($record === null) {
                return null;
            }
            $counter = OneTimeCode::matchingCounter($record->secret, $typed, $now, $record->lastCounter);
            if ($counter === null) {
                return null;
            }
            $right = true;

            return $record->withCounter($counter);
        });

        return $right;
    }

    /** How many recovery codes the account has left. */
    public function recoveryCodesLeft(string $accountId): int
    {
        return count($this->store->find($accountId)->recoveryHashes ?? []);
    }

    /**
     * New recovery codes in place of the old, to show the person once.
     *
     * @return list<string>
     */
    public function renewRecoveryCodes(string $accountId): array
    {
        $codes = RecoveryCodes::make();
        $hashes = array_map(fn (string $recovery): string => RecoveryCodes::hash($this->hash, $accountId, $recovery), $codes);
        $made = false;
        $this->store->change($accountId, static function (?TwoStepRecord $record) use ($hashes, &$made): ?TwoStepRecord {
            if ($record === null) {
                return null;
            }
            $made = true;

            return $record->withRecovery($hashes);
        });
        if (!$made) {
            return [];
        }
        $this->activity->record('two_step.recovery_codes_renewed', [], 'account', $accountId);

        return $codes;
    }

    /** Starts again: the account sets up a new key at its next sign-in. */
    public function reset(string $accountId, ?Actor $actor = null): void
    {
        if (!$this->isSetUp($accountId)) {
            return;
        }
        $this->store->forget($accountId);
        $this->activity->record('two_step.reset', [], 'account', $accountId, $actor);
    }

    private function useRecoveryCode(string $accountId, string $code): bool
    {
        $hash = RecoveryCodes::hash($this->hash, $accountId, $code);
        $used = false;
        $left = 0;
        $this->store->change($accountId, static function (?TwoStepRecord $record) use ($hash, &$used, &$left): ?TwoStepRecord {
            if ($record === null) {
                return null;
            }
            foreach ($record->recoveryHashes as $index => $stored) {
                if (hash_equals($stored, $hash)) {
                    $hashes = $record->recoveryHashes;
                    unset($hashes[$index]);
                    $used = true;
                    $left = count($hashes);

                    return $record->withRecovery(array_values($hashes));
                }
            }

            return null;
        });
        if ($used) {
            $this->activity->record('two_step.recovery_code_used', ['left' => $left], 'account', $accountId);
        }

        return $used;
    }
}
