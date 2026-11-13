<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

use DateTimeImmutable;

/**
 * Overrides held for one run only. With $writable false it is the store of an installation that keeps its settings in
 * .env alone (one without a database): nothing can be saved.
 */
final class MemorySettingsStore implements SettingsStore
{
    /** @var array<string,StoredSetting> */
    private array $stored = [];

    public function __construct(private readonly bool $writable = true)
    {
    }

    public function all(): array
    {
        return $this->stored;
    }

    public function save(string $name, string $value, bool $secret, DateTimeImmutable $at, ?string $by): void
    {
        if (!$this->writable) {
            throw new SettingsRefused(['' => 'This installation keeps its settings in .env: change them there.']);
        }
        $this->stored[$name] = new StoredSetting($value, $secret, $at->format('Y-m-d H:i:sP'), $by);
    }

    public function remove(string $name): bool
    {
        $had = isset($this->stored[$name]);
        unset($this->stored[$name]);

        return $had;
    }

    public function forgetAccount(string $accountId): int
    {
        $changed = 0;
        foreach ($this->stored as $name => $setting) {
            if ($setting->updatedBy === $accountId) {
                $this->stored[$name] = new StoredSetting($setting->value, $setting->secret, $setting->updatedAt, null);
                ++$changed;
            }
        }

        return $changed;
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }
}
