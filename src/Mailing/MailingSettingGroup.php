<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

use CattoPHP\Settings\Setting;
use CattoPHP\Settings\SettingGroup;
use CattoPHP\Settings\SettingsProvider;

/**
 * Settings > Mailing: where news is sent from and through. A class of its own, so reading the settings (MailingSettings)
 * never builds the settings catalogue from inside itself. `.env` gives each starting value and Settings overrides it with a
 * Reset; the mail server and catto-mail's key and webhook secrets are kept encrypted. MailingSettings checks each value.
 */
final class MailingSettingGroup implements SettingsProvider
{
    public const GROUP = 'mailing';

    public function settingGroups(): iterable
    {
        $settings = [];
        foreach (MailingSettings::FIELDS as [$name, $label, $secret]) {
            $settings[] = new Setting($name, $label, optional: true, secret: $secret, longest: 2000);
        }
        yield new SettingGroup(self::GROUP, 'Mailing', 'Where news is sent from and through.', $settings);
    }
}
