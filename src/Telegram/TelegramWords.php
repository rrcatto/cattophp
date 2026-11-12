<?php

declare(strict_types=1);

namespace CattoPHP\Telegram;

use CattoPHP\Installation\Installation;
use CattoPHP\Runtime\SiteMode;

/**
 * How the site's Telegram messages begin and end: [DEMO] on a demo site, as its emails do, and the site's own name at the
 * end of a message to the owner, because one bot may serve several sites.
 */
class TelegramWords
{
    public function __construct(private readonly SiteMode $site, private readonly Installation $installation)
    {
    }

    /** A message to the owner: [DEMO] first on a demo site, the site's domain last. */
    public function worded(string $words): string
    {
        $domain = $this->installation->domain();

        return $this->demo() . $words . ($domain !== '' ? ' (' . $domain . ')' : '');
    }

    /** What a message to a person begins with: [DEMO] on a demo site. */
    public function demo(): string
    {
        return $this->site->isDemo() ? '[DEMO] ' : '';
    }

    /** The site's name in a message: its domain, or "this site". */
    public function domain(): string
    {
        $domain = $this->installation->domain();

        return $domain !== '' ? $domain : 'this site';
    }
}
