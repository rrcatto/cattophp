<?php

declare(strict_types=1);

namespace CattoPHP\Access;

use InvalidArgumentException;

/**
 * Something a person may be allowed to do, named resource first and action last in capitals, such as
 * `PLATFORM.SETTINGS.VIEW`. Code asks for a permission (`#[IsGranted('PLATFORM.SETTINGS.VIEW')]`, `is_granted()` in a
 * template), never for a role. A key starting `SYSTEM.` belongs to administrators alone: no other role can hold it.
 */
final class Permission
{
    public const PATTERN = '/^[A-Z][A-Z0-9_]*(\.[A-Z][A-Z0-9_]*){1,3}$/';

    public function __construct(public readonly string $key, public readonly string $group, public readonly string $description)
    {
        if (preg_match(self::PATTERN, $key) !== 1) {
            throw new InvalidArgumentException(sprintf('A permission is named like AREA.THING.ACTION in capitals: "%s" is not.', $key));
        }
    }

    public function isAdministratorsOnly(): bool
    {
        return str_starts_with($this->key, 'SYSTEM.');
    }
}
