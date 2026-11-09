<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

use InvalidArgumentException;

/**
 * Every setting the Settings page may change, from every provider. Anything not in it (keys, the database, host
 * names, safety switches) stays in .env alone. A setting named twice is a mistake, refused when the catalogue is made.
 */
final class SettingsCatalogue
{
    /** @var array<string,SettingGroup> */
    private array $groups = [];

    /** @var array<string,string> setting name => group key */
    private array $names = [];

    /**
     * @param iterable<SettingsProvider> $providers
     * @param array<string,string> $defaults a project's own defaults, by name, in place of the settings' own: used when
     *                                       .env and the Settings page give nothing
     */
    public function __construct(iterable $providers, array $defaults = [])
    {
        foreach ($providers as $provider) {
            foreach ($provider->settingGroups() as $group) {
                if (isset($this->groups[$group->key])) {
                    throw new InvalidArgumentException(sprintf('Two settings groups are called "%s".', $group->key));
                }
                foreach ($group->settings as $name => $setting) {
                    if (isset($this->names[$name])) {
                        throw new InvalidArgumentException(sprintf('The setting %s is in two groups, "%s" and "%s".', $name, $this->names[$name], $group->key));
                    }
                    $this->names[$name] = $group->key;
                }
                $this->groups[$group->key] = $group;
            }
        }
        foreach ($defaults as $name => $default) {
            $key = $this->names[$name] ?? throw new InvalidArgumentException(sprintf('A default is given for %s, which is no setting.', $name));
            $this->groups[$key] = $this->groups[$key]->with([$name => $this->groups[$key]->settings[$name]->withDefault($default)]);
        }
    }

    /** @return array<string,SettingGroup> */
    public function groups(): array
    {
        return $this->groups;
    }

    public function group(string $key): SettingGroup
    {
        return $this->groups[$key] ?? throw new InvalidArgumentException(sprintf('There is no settings group "%s".', $key));
    }

    public function has(string $name): bool
    {
        return isset($this->names[$name]);
    }

    public function setting(string $name): Setting
    {
        $group = $this->names[$name] ?? throw new InvalidArgumentException(sprintf('No setting is called %s.', $name));

        return $this->groups[$group]->settings[$name];
    }

    public function groupOf(string $name): string
    {
        return $this->names[$name] ?? throw new InvalidArgumentException(sprintf('No setting is called %s.', $name));
    }
}
