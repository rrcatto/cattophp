<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

/** An account that can sign in, as the application describes it. */
final class SignInAccount
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly string $name,
        public readonly bool $administrator = false,
    ) {
    }
}
