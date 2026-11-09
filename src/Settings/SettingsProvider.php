<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

/**
 * Says which settings a module or the application lets the Settings page change. Each is a service tagged
 * `cattophp.settings` (done automatically for a class implementing this). CattoPHP's own foundations provide theirs.
 */
interface SettingsProvider
{
    public const TAG = 'cattophp.settings';

    /** @return iterable<SettingGroup> */
    public function settingGroups(): iterable;
}
