<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use CattoPHP\Foundation\Duration;
use CattoPHP\Settings\Setting;
use CattoPHP\Settings\SettingGroup;
use CattoPHP\Settings\SettingsProvider;
use CattoPHP\Settings\SettingType;

/**
 * The Campaign Marketing Manager's settings group, as the settings catalogue reads it: a provider with no dependencies,
 * since the catalogue is built before Settings and a provider must never need Settings itself. MarketingSettings reads
 * them. Every length of time is a setting, written as everywhere (30d, 4w2d, 30m).
 */
final class MarketingSettingGroup implements SettingsProvider
{
    public const GROUP = 'marketing';

    /** The choices of which campaign a sale counts for. */
    public const SALE_COUNTS_FOR = ['last' => 'The last campaign the person came by', 'first' => 'The first campaign the person came by'];

    /** The choices of when a campaign's promotion code gets it a sale. */
    public const PROMOTION_COUNTS = ['no_link' => 'Only when the customer followed no link', 'always' => 'Always, even over another campaign\'s link'];

    public function settingGroups(): iterable
    {
        yield new SettingGroup(self::GROUP, 'Campaign Marketing Manager', 'How long visitors are remembered, which campaign a sale counts for, how connected sites talk, and how long the records are kept.', [
            new Setting('MARKETING_VISITOR_REMEMBERED', 'Remember a visitor for', SettingType::Duration, '30d',
                'After their last arrival by a tracking link. ' . Duration::HOW, shortest: '1d'),
            new Setting('MARKETING_SALE_COUNTS_WITHIN', 'A sale counts for a campaign within', SettingType::Duration, '30d',
                'Of the click that brought the person. A change counts for sales paid from now on.', shortest: '1h'),
            new Setting('MARKETING_SALE_COUNTS_FOR', 'A sale counts for', SettingType::Choice, 'last', choices: self::SALE_COUNTS_FOR),
            new Setting('MARKETING_PROMOTION_COUNTS', 'A promotion code counts for its campaign', SettingType::Choice, 'no_link',
                'Whichever is chosen, a sale keeps both the campaign of the links the customer followed and the campaign of the code, and a change counts for sales paid from now on.',
                choices: self::PROMOTION_COUNTS),
            new Setting('MARKETING_SAME_VISIT', 'Openings of the same link count as one visit within', SettingType::Duration, '30m',
                'A second opening by the same visitor within this time is the same visit, not another click.', shortest: '1m'),
            new Setting('MARKETING_IP_KEPT', 'Keep IP addresses for', SettingType::Duration, '30d',
                'After this the address of each opening of a tracking link is removed; its country stays.', shortest: '1d'),
            new Setting('MARKETING_HISTORY_KEPT', 'Keep a visitor\'s history for', SettingType::Duration, '365d',
                'After this a visitor\'s history is made anonymous; the totals stay.', shortest: '30d'),
            new Setting('MARKETING_LATEST_SALES', 'Latest sales shows the last', SettingType::Duration, '30d', shortest: '1d'),
            new Setting('MARKETING_ATTENTION_ENDING', 'Tell me of a campaign ending within', SettingType::Duration, '7d',
                'Shown under Needs your attention on the overview.', shortest: '1d'),
            new Setting('MARKETING_ATTENTION_NO_CLICKS', 'Tell me of a publication with no click after', SettingType::Duration, '7d',
                'Shown under Needs your attention on the overview.', shortest: '1d'),
            new Setting('MARKETING_HONOUR_DO_NOT_TRACK', 'Honour a browser that asks not to be tracked', SettingType::Switch, 'true',
                'Its click is counted with its country, and no visitor, cookie or IP address is kept.'),
            new Setting('MARKETING_PICTURE_LARGEST', 'Largest advert picture, in kilobytes', SettingType::Number, '5120',
                'A JPEG, PNG or WebP picture up to this size can be uploaded for an advert or a landing page.', min: 50, max: 51200),
            new Setting('MARKETING_LANDING_RECENT', 'The landing pages list counts visits in the last', SettingType::Duration, '30d',
                'Each page\'s visits over this time, beside its name on Marketing > Landing pages.', shortest: '1d'),
            new Setting('MARKETING_LANDING_PICTURE_CACHED', 'Browsers keep a published landing page\'s pictures for', SettingType::Duration, '1h',
                'How long a browser or a cache may keep a picture of a published page before asking again.', shortest: '1m'),
            new Setting('MARKETING_SITE_TOLERANCE', 'Connected sites\' clocks may be apart by', SettingType::Duration, '5m',
                'A link or report signed by a connected site is accepted only this close to this site\'s time, so it is also how long one stays valid.', shortest: '1m'),
            new Setting('MARKETING_SITE_RETRY_FIRST', 'Try a report to a connected site again first after', SettingType::Duration, '1m',
                'A report not delivered is tried again after this, then after twice as long each time.', shortest: '10s'),
            new Setting('MARKETING_SITE_RETRY_LONGEST', 'Try a report to a connected site again at most every', SettingType::Duration, '1h',
                'However often a report has failed, its tries are never longer apart than this.', shortest: '1m'),
            new Setting('MARKETING_SITE_REPORTS_KEPT', 'Keep reports to and from connected sites for', SettingType::Duration, '30d',
                'A report not delivered this long after it was made is given up; reports delivered and received are removed after it.', shortest: '1d'),
        ]);
    }
}
