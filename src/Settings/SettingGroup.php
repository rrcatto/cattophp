<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

use InvalidArgumentException;

/** A group of settings saved and reset together, one section of the Settings page. */
final class SettingGroup
{
    /** @var array<string,Setting> by name */
    public readonly array $settings;

    /**
     * The same group with some of its settings replaced, by name.
     *
     * @param array<string,Setting> $replacements
     */
    public function with(array $replacements): self
    {
        return new self($this->key, $this->label, $this->description, array_values(array_replace($this->settings, array_intersect_key($replacements, $this->settings))));
    }

    /** @param list<Setting> $settings */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        array $settings,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
            throw new InvalidArgumentException(sprintf('A settings group\'s key is in small letters: "%s" is not.', $key));
        }
        $byName = [];
        foreach ($settings as $setting) {
            $byName[$setting->name] = $setting;
        }
        $this->settings = $byName;
    }
}
