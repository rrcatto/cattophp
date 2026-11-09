<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

/** An override as it is stored: a secret's value still encrypted. */
final class StoredSetting
{
    public function __construct(
        public readonly string $value,
        public readonly bool $secret,
        public readonly string $updatedAt,
        public readonly ?string $updatedBy,
    ) {
    }
}
