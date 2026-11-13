<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

use DateTimeImmutable;

/**
 * A sign-in link as it is stored: never the link itself, only a hash of it. A link sent to an address joining
 * (JoiningAccounts) has no account until it is used: its accountId is empty and $joining holds the address.
 */
final class SignInLink
{
    public function __construct(
        public readonly string $accountId,
        public readonly DateTimeImmutable $expiresAt,
        public readonly ?string $returnPath = null,
        public readonly ?string $joining = null,
    ) {
    }
}
