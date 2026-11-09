<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

use DateTimeImmutable;

/** A sign-in link as it is stored: never the link itself, only a hash of it. */
final class SignInLink
{
    public function __construct(
        public readonly string $accountId,
        public readonly DateTimeImmutable $expiresAt,
        public readonly ?string $returnPath = null,
    ) {
    }
}
