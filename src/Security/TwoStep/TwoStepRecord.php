<?php

declare(strict_types=1);

namespace CattoPHP\Security\TwoStep;

use DateTimeImmutable;

/** One account's two-step sign-in: its key, the last step used, and its unused recovery codes as keyed hashes. */
final class TwoStepRecord
{
    /**
     * @param list<string> $recoveryHashes
     * @param DateTimeImmutable|null $setUpAt when the key was set up; unknown for a record kept before it was noted
     */
    public function __construct(
        public readonly string $secret,
        public readonly int $lastCounter,
        public readonly array $recoveryHashes = [],
        public readonly ?DateTimeImmutable $setUpAt = null,
    ) {
    }

    public function withCounter(int $counter): self
    {
        return new self($this->secret, $counter, $this->recoveryHashes, $this->setUpAt);
    }

    /** @param array<int,string> $hashes */
    public function withRecovery(array $hashes): self
    {
        return new self($this->secret, $this->lastCounter, array_values($hashes), $this->setUpAt);
    }
}
