<?php

declare(strict_types=1);

namespace CattoPHP\Signup;

use CattoPHP\Settings\Setting;
use CattoPHP\Settings\SettingGroup;
use CattoPHP\Settings\SettingsProvider;

/** The sign-up check's settings: the countries a registration is allowed from and held from. */
final class SignupSettingGroup implements SettingsProvider
{
    public const GROUP = 'signup';

    public function settingGroups(): iterable
    {
        yield new SettingGroup(self::GROUP, 'Sign-up checks', 'The countries a registration is allowed from and held from.', [
            new Setting(SignupCountries::ENV_ALLOWED, 'Allowed countries', optional: true, longest: 2000),
            new Setting(SignupCountries::ENV_BLOCKED, 'Blocked countries', optional: true, longest: 2000),
        ]);
    }
}
