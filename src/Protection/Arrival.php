<?php

declare(strict_types=1);

namespace CattoPHP\Protection;

/** Where a visitor came from: a search engine, a social network, an email, another site, this site, a shared link or nowhere. */
final class Arrival
{
    /**
     * Where a shared link was shared: the utm_source of an address tagged utm_medium=share, with its words. Only these
     * count; anything else is an ordinary visit.
     */
    public const SHARED_ON = [
        'facebook' => 'Shared on Facebook', 'whatsapp' => 'Shared on WhatsApp', 'telegram' => 'Shared on Telegram',
        'linkedin' => 'Shared on LinkedIn', 'x' => 'Shared on X', 'email' => 'Shared by email',
        'copied_link' => 'A copied share link', 'share_sheet' => "Shared from a phone's share sheet",
    ];

    public const SOURCES = ['inside', 'direct', 'search', 'social', 'email', 'site', 'share'];

    /**
     * A visit that came through a shared link: where it was shared, and the source 'share'; null for any other address.
     * It counts whatever the referrer says, since an app such as WhatsApp or Telegram sends none.
     *
     * @param array<array-key,mixed> $query
     * @return array{0:string,1:string}|null
     */
    public static function sharedVia(array $query): ?array
    {
        if (($query['utm_medium'] ?? null) !== 'share') return null;
        $source = $query['utm_source'] ?? null;

        return is_string($source) && isset(self::SHARED_ON[$source]) ? [$source, 'share'] : null;
    }

    /**
     * Where a visitor came from, by the referrer.
     *
     * @return array{0:string,1:string} the name of the site they came from, and which kind that is
     */
    public static function cameFrom(string $referrer, string $thisHost): array
    {
        $host = strtolower((string) parse_url($referrer, PHP_URL_HOST));
        if ($host === '') return ['', 'direct'];
        if ($host === strtolower($thisHost)) return ['', 'inside'];
        $host = (string) preg_replace('/^www\./', '', $host);
        $kind = match (true) {
            preg_match('/(^|\.)(google|bing|duckduckgo|yahoo|yandex|baidu|ecosia|brave)\./', $host) === 1 => 'search',
            preg_match('/(^|\.)(facebook|fb|instagram|twitter|t|x|linkedin|whatsapp|youtube|tiktok|reddit|pinterest)\.(com|co|me|net)$/', $host) === 1 => 'social',
            preg_match('/(^|\.)(mail|outlook|webmail)\./', $host) === 1 => 'email',
            default => 'site',
        };

        return [mb_substr($host, 0, 200), $kind];
    }
}
