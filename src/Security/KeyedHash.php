<?php

declare(strict_types=1);

namespace CattoPHP\Security;

use RuntimeException;

/**
 * A keyed hash (HMAC-SHA256) of something that must be recognised later but never kept readable: a recovery code, a
 * one-time sign-in code, an identity number's fingerprint. Each use names its purpose, so a hash made for one purpose
 * never matches another's. The key is the application's key.
 */
final class KeyedHash
{
    public function __construct(private readonly string $applicationKey)
    {
    }

    /** 64 hexadecimal characters. */
    public function of(string $purpose, string $message): string
    {
        if ($this->applicationKey === '') {
            throw new RuntimeException('The application key (APP_KEY) must be set before anything can be hashed with it.');
        }

        return hash_hmac('sha256', $message, $purpose . ':' . $this->applicationKey);
    }

    public function matches(string $purpose, string $message, string $hash): bool
    {
        return hash_equals($hash, $this->of($purpose, $message));
    }
}
