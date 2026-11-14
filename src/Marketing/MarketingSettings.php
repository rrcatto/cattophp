<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use CattoPHP\Foundation\Duration;
use CattoPHP\Settings\Settings;

/**
 * The Campaign Marketing Manager's settings, declared by MarketingSettingGroup (a class of its own, so reading settings
 * here never builds the settings catalogue from inside itself). As every setting, `.env` gives the starting value
 * (MARKETING_*) and Settings overrides it with a Reset; Administration > Settings, or the module's own Settings page,
 * saves the group. Without Settings (a test, a command) each reads its starting value.
 */
final class MarketingSettings
{
    public const GROUP = MarketingSettingGroup::GROUP;

    /** Every setting of the group, in the page's order. */
    public const NAMES = ['MARKETING_VISITOR_REMEMBERED', 'MARKETING_SALE_COUNTS_WITHIN', 'MARKETING_SALE_COUNTS_FOR', 'MARKETING_PROMOTION_COUNTS', 'MARKETING_SAME_VISIT', 'MARKETING_IP_KEPT',
        'MARKETING_HISTORY_KEPT', 'MARKETING_LATEST_SALES', 'MARKETING_ATTENTION_ENDING', 'MARKETING_ATTENTION_NO_CLICKS', 'MARKETING_HONOUR_DO_NOT_TRACK', 'MARKETING_PICTURE_LARGEST',
        'MARKETING_LANDING_RECENT', 'MARKETING_LANDING_PICTURE_CACHED', 'MARKETING_SITE_TOLERANCE', 'MARKETING_SITE_RETRY_FIRST', 'MARKETING_SITE_RETRY_LONGEST', 'MARKETING_SITE_REPORTS_KEPT'];

    private const DEFAULTS = ['MARKETING_VISITOR_REMEMBERED' => '30d', 'MARKETING_SALE_COUNTS_WITHIN' => '30d', 'MARKETING_SALE_COUNTS_FOR' => 'last', 'MARKETING_PROMOTION_COUNTS' => 'no_link',
        'MARKETING_SAME_VISIT' => '30m', 'MARKETING_IP_KEPT' => '30d', 'MARKETING_HISTORY_KEPT' => '365d', 'MARKETING_LATEST_SALES' => '30d', 'MARKETING_ATTENTION_ENDING' => '7d',
        'MARKETING_ATTENTION_NO_CLICKS' => '7d', 'MARKETING_HONOUR_DO_NOT_TRACK' => 'true', 'MARKETING_PICTURE_LARGEST' => '5120', 'MARKETING_LANDING_RECENT' => '30d',
        'MARKETING_LANDING_PICTURE_CACHED' => '1h', 'MARKETING_SITE_TOLERANCE' => '5m', 'MARKETING_SITE_RETRY_FIRST' => '1m', 'MARKETING_SITE_RETRY_LONGEST' => '1h', 'MARKETING_SITE_REPORTS_KEPT' => '30d'];

    public function __construct(private readonly ?Settings $settings = null)
    {
    }

    public function get(string $name): string
    {
        return $this->settings?->get($name) ?? (self::DEFAULTS[$name] ?? '');
    }

    /** How long a visitor is remembered after their last arrival by a tracking link: the cookie's life. */
    public function visitorRemembered(): Duration
    {
        return $this->duration('MARKETING_VISITOR_REMEMBERED');
    }

    /** A sale counts for a campaign when it comes within this time of the click. */
    public function saleCountsWithin(): Duration
    {
        return $this->duration('MARKETING_SALE_COUNTS_WITHIN');
    }

    /** Which campaign a sale counts for: last or first. */
    public function saleCountsFor(): string
    {
        return $this->get('MARKETING_SALE_COUNTS_FOR') === 'first' ? 'first' : 'last';
    }

    /**
     * When a sale's promotion code is a campaign's, whether that campaign gets the sale only when the buyer followed no
     * counted link within the period (no_link), or always, even over another campaign's link (always).
     */
    public function promotionCounts(): string
    {
        return $this->get('MARKETING_PROMOTION_COUNTS') === 'always' ? 'always' : 'no_link';
    }

    /** How far apart two connected sites' clocks may be, and so how long a signed forward or report stays valid. */
    public function siteTolerance(): Duration
    {
        return $this->duration('MARKETING_SITE_TOLERANCE');
    }

    /** The first wait before a report to a connected site that was not delivered is tried again; each wait after it is twice as long. */
    public function siteRetryFirst(): Duration
    {
        return $this->duration('MARKETING_SITE_RETRY_FIRST');
    }

    /** The longest wait between two tries of a report to a connected site. */
    public function siteRetryLongest(): Duration
    {
        return $this->duration('MARKETING_SITE_RETRY_LONGEST');
    }

    /** How long a report to a connected site is tried before it is given up, and how long reports out and in are kept. */
    public function siteReportsKept(): Duration
    {
        return $this->duration('MARKETING_SITE_REPORTS_KEPT');
    }

    /** A second opening of the same link by the same visitor within this time is the same visit. */
    public function sameVisit(): Duration
    {
        return $this->duration('MARKETING_SAME_VISIT');
    }

    public function ipKept(): Duration
    {
        return $this->duration('MARKETING_IP_KEPT');
    }

    public function historyKept(): Duration
    {
        return $this->duration('MARKETING_HISTORY_KEPT');
    }

    /** What "Latest sales" shows. */
    public function latestSales(): Duration
    {
        return $this->duration('MARKETING_LATEST_SALES');
    }

    /** A campaign ending within this time needs attention. */
    public function attentionEnding(): Duration
    {
        return $this->duration('MARKETING_ATTENTION_ENDING');
    }

    /** A publication with no click this long after it was published needs attention. */
    public function attentionNoClicks(): Duration
    {
        return $this->duration('MARKETING_ATTENTION_NO_CLICKS');
    }

    public function honoursDoNotTrack(): bool
    {
        return in_array(strtolower($this->get('MARKETING_HONOUR_DO_NOT_TRACK')), ['1', 'true', 'on', 'yes'], true);
    }

    /** How far back the landing pages list counts each page's visits. */
    public function landingRecent(): Duration
    {
        return $this->duration('MARKETING_LANDING_RECENT');
    }

    /** How long a browser or a cache may keep a published landing page's picture. */
    public function landingPictureCached(): Duration
    {
        return $this->duration('MARKETING_LANDING_PICTURE_CACHED');
    }

    /** The largest advert or landing page picture, in bytes. */
    public function pictureLargest(): int
    {
        $kilobytes = (int) $this->get('MARKETING_PICTURE_LARGEST');

        return ($kilobytes >= 50 && $kilobytes <= 51200 ? $kilobytes : 5120) * 1024;
    }

    private function duration(string $name): Duration
    {
        $value = $this->get($name);

        return Duration::parse(Duration::isValid($value) ? $value : self::DEFAULTS[$name]);
    }
}
