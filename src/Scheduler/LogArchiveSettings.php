<?php

declare(strict_types=1);

namespace CattoPHP\Scheduler;

use CattoPHP\Settings\Setting;
use CattoPHP\Settings\SettingGroup;
use CattoPHP\Settings\SettingsProvider;
use CattoPHP\Settings\SettingType;

/** How long the log archives are kept: LOG_ARCHIVES_KEPT_FOR, at least a day, two weeks unless .env or Settings says. */
final class LogArchiveSettings implements SettingsProvider
{
    public function settingGroups(): iterable
    {
        yield new SettingGroup('logs', 'Log archives', 'How long the log archives are kept.', [
            new Setting(LogArchive::KEEP, 'Kept for', SettingType::Duration, LogArchive::DEFAULT_KEEP, shortest: '1d'),
        ]);
    }
}
