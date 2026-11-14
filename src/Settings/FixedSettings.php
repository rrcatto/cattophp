<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

use InvalidArgumentException;

/**
 * The settings the installation holds fixed: those its configuration names (`cattophp.settings.fixed`, shown on the
 * Settings page as fixed; `cattophp.settings.hidden`, left off it) and those its FixedSettingsProvider services name.
 * A name in capitals is a setting, one in small letters a group, standing for every setting in it. A hidden setting is
 * fixed too. A name that is no setting or group of the catalogue is a mistake, refused when first asked.
 */
final class FixedSettings
{
    /** @var array<string,bool>|null setting name => hidden, once worked out */
    private ?array $worked = null;

    /**
     * @param list<string> $shown fixed and shown, from the configuration
     * @param list<string> $hidden fixed and left off the page, from the configuration
     * @param iterable<FixedSettingsProvider> $providers
     */
    public function __construct(private readonly array $shown = [], private readonly array $hidden = [], private readonly iterable $providers = [])
    {
    }

    /** Whether the setting is fixed: shown, or left off the page, and never changed there. */
    public function isFixed(SettingsCatalogue $catalogue, string $name): bool
    {
        return isset($this->all($catalogue)[$name]);
    }

    /** Whether the setting is fixed and left off the Settings page. */
    public function isHidden(SettingsCatalogue $catalogue, string $name): bool
    {
        return $this->all($catalogue)[$name] ?? false;
    }

    /**
     * Every fixed setting, by name, with whether it is hidden.
     *
     * @return array<string,bool>
     */
    public function all(SettingsCatalogue $catalogue): array
    {
        if ($this->worked !== null) {
            return $this->worked;
        }
        $shown = $this->shown;
        $hidden = $this->hidden;
        foreach ($this->providers as $provider) {
            foreach ($provider->fixedSettings() as $name) {
                $shown[] = $name;
            }
            foreach ($provider->hiddenSettings() as $name) {
                $hidden[] = $name;
            }
        }
        $all = [];
        foreach ($shown as $name) {
            foreach (self::settingsNamed($catalogue, $name) as $setting) {
                $all[$setting] ??= false;
            }
        }
        foreach ($hidden as $name) {
            foreach (self::settingsNamed($catalogue, $name) as $setting) {
                $all[$setting] = true;
            }
        }

        return $this->worked = $all;
    }

    /** @return list<string> the settings a name stands for: itself, or every setting of the group */
    private static function settingsNamed(SettingsCatalogue $catalogue, string $name): array
    {
        $name = trim($name);
        if ($catalogue->has($name)) {
            return [$name];
        }
        if (isset($catalogue->groups()[$name])) {
            return array_keys($catalogue->group($name)->settings);
        }

        throw new InvalidArgumentException(sprintf('"%s" is held fixed, but it is no setting or group of settings: name a setting as in .env (APP_URL) or a group by its key (sign_in).', $name));
    }
}
