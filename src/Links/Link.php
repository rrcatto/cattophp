<?php

declare(strict_types=1);

namespace CattoPHP\Links;

use DateTimeImmutable;

/**
 * A link that works once, as it is kept: never the link itself, only a hash of it. What it is for (its purpose: a
 * sign-in, a registration, confirming an address), the address and the account it is for (either may be unknown: a
 * registration has no account yet), what it carries, and where it was asked for from (a fingerprint the project gives,
 * which requests are counted by).
 */
final class Link
{
    /** @param array<string,mixed> $context */
    public function __construct(
        public readonly string $id,
        /** The hash it is kept by: what a project derives from the link, such as a code sent with it, is keyed by this. */
        public readonly string $hash,
        public readonly string $purpose,
        public readonly ?string $email,
        public readonly ?string $accountId,
        public readonly array $context,
        public readonly ?string $requestedFrom,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $expiresAt,
        public readonly ?DateTimeImmutable $usedAt = null,
    ) {
    }

    public function isUsable(DateTimeImmutable $now): bool
    {
        return $this->usedAt === null && $this->expiresAt > $now;
    }
}
