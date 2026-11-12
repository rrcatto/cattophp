<?php

declare(strict_types=1);

namespace CattoPHP\Search;

use CattoPHP\Settings\Setting;
use CattoPHP\Settings\SettingGroup;
use CattoPHP\Settings\SettingsProvider;
use CattoPHP\Settings\SettingType;

/**
 * The settings SearchSettings reads, apart from it: a settings provider never depends on Settings, which is built from
 * the providers.
 */
final class SearchSettingGroup implements SettingsProvider
{
    public const GROUP = 'search_engines';

    public function settingGroups(): iterable
    {
        yield new SettingGroup(self::GROUP, 'Search engines and AI', 'What AI crawlers may do, and the codes that prove the site is yours to Google and Bing.', [
            new Setting(SearchSettings::NAMES['ai_crawlers'], 'AI crawlers', SettingType::Choice, SearchSettings::ALLOW_ALL, choices: [
                SearchSettings::ALLOW_ALL => 'Let every AI crawler in',
                SearchSettings::SEARCH_ONLY => 'Let in AI search and answers, keep out training',
                SearchSettings::BLOCK_ALL => 'Keep every AI crawler out',
            ]),
            new Setting(SearchSettings::NAMES['google'], 'Google Search Console code', optional: true, longest: 100),
            new Setting(SearchSettings::NAMES['bing'], 'Bing Webmaster Tools code', optional: true, longest: 100),
        ]);
    }
}
