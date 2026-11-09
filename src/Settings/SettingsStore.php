<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

use DateTimeImmutable;

/** Where the overrides the Settings page saves are kept. */
interface SettingsStore
{
    /** @return array<string,StoredSetting> by name */
    public function all(): array;

    public function save(string $name, string $value, bool $secret, DateTimeImmutable $at, ?string $by): void;

    /** Removes an override; true when there was one. */
    public function remove(string $name): bool;

    /** Whether overrides can be saved at all: false for an installation that keeps its settings in .env alone. */
    public function isWritable(): bool;
}
