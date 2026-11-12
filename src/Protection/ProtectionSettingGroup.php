<?php

declare(strict_types=1);

namespace CattoPHP\Protection;

use CattoPHP\Settings\Setting;
use CattoPHP\Settings\SettingGroup;
use CattoPHP\Settings\SettingsProvider;
use CattoPHP\Settings\SettingType;

/** The site protection settings (`SITE_*`) in the Settings catalogue, each with its starting value. */
final class ProtectionSettingGroup implements SettingsProvider
{
    public function settingGroups(): iterable
    {
        $settings = [];
        foreach (ProtectionSettings::RULES as $rule) {
            $settings[] = new Setting($rule['env'], $rule['name'], default: $rule['start']);
        }
        foreach (ProtectionSettings::STARTS as $name => $start) {
            $label = ucfirst(strtolower(str_replace('_', ' ', substr($name, 5))));
            $settings[] = match (true) {
                $name === 'SITE_NEVER_LOCK_OUT' => new Setting($name, 'Never lock out', SettingType::Paragraph, optional: true),
                str_ends_with($name, '_FOR') || $name === 'SITE_TIMEOUT_LONGEST' => new Setting($name, $label, SettingType::Duration, $start),
                $start === 'on' || $start === 'off' => new Setting($name, $label, SettingType::Choice, $start, choices: ['on' => 'On', 'off' => 'Off']),
                default => new Setting($name, $label, default: $start),
            };
        }
        yield new SettingGroup(ProtectionSettings::GROUP, 'Site protection', 'What one address may do before it is timed out or locked out.', $settings);
    }
}
