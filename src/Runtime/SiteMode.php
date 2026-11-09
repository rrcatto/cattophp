<?php

declare(strict_types=1);

namespace CattoPHP\Runtime;

use CattoPHP\Settings\Settings;

/**
 * Whether the site is a demo or live: APP_SITE_MODE, which the Settings page overrides. Anything but live, or nothing,
 * is demo, so a site never goes live by a typing mistake. A demo site says so on every page and at the start of every
 * email, and asks search engines not to list it; a live site does none of that. How the program runs (APP_ENV) is a
 * separate thing and is not read here.
 *
 * A project with no such distinction (a tool for one person, say) turns it off in its configuration
 * (`cattophp.site_mode: false`), and is then never a demo.
 */
final class SiteMode
{
    public const DEMO = 'demo';
    public const LIVE = 'live';
    public const EMAIL_PREFIX = '[DEMO] ';

    public const HEADING = 'Demo mode';
    public const NOTICE = 'This site is not live. It is being tested and developed, and nothing on it is real.';
    public const EMAIL_NOTICE = 'This email comes from a demo site for testing and development. Nothing in it is real.';

    /** @param Settings|null $settings null for a project without the distinction, which is never a demo */
    public function __construct(private readonly ?Settings $settings, private readonly ?string $fixed = null)
    {
    }

    /** A mode that reads no settings, with the standard words: for tests and tools. */
    public static function fixed(string $mode): self
    {
        return new self(null, self::normalise($mode));
    }

    public function mode(): string
    {
        if ($this->fixed !== null) {
            return $this->fixed;
        }

        return $this->settings === null ? self::LIVE : self::normalise($this->settings->get('APP_SITE_MODE'));
    }

    public function isDemo(): bool
    {
        return $this->mode() === self::DEMO;
    }

    public function heading(): string
    {
        return $this->words('APP_DEMO_HEADING', self::HEADING);
    }

    public function notice(): string
    {
        return $this->words('APP_DEMO_NOTICE', self::NOTICE);
    }

    public function emailNotice(): string
    {
        return $this->words('APP_DEMO_EMAIL_NOTICE', self::EMAIL_NOTICE);
    }

    private static function normalise(string $value): string
    {
        return strtolower(trim($value)) === self::LIVE ? self::LIVE : self::DEMO;
    }

    private function words(string $name, string $standard): string
    {
        $words = $this->settings === null ? '' : trim($this->settings->get($name));

        return $words === '' ? $standard : $words;
    }
}
