<?php

declare(strict_types=1);

namespace CattoPHP\Http;

/**
 * What a browser says it is: a person's browser and on what, one years out of date, a script, a search engine, another
 * crawler, or nothing at all. People and automated visitors are told apart by this and by how they behave, so a careful
 * bot that describes itself as a browser and behaves normally counts as a person.
 */
final class VisitorAgent
{
    public const BROWSER = 'browser';
    public const OUTDATED = 'outdated';
    public const SCRIPT = 'script';
    public const SEARCH = 'search';
    public const CRAWLER = 'crawler';
    public const NONE = 'none';

    /** The oldest major version taken for a browser still in use (Chrome and Firefox 80: 2020, Safari 11: 2017). */
    private const OLDEST = ['Chrome' => 80, 'Firefox' => 80, 'Safari' => 11];

    /** Search engines, by the word their crawler sends, and the host names that prove one is what it says. */
    public const SEARCH_ENGINES = [
        'Googlebot' => ['googlebot.com', 'google.com', 'googleusercontent.com'],
        'bingbot' => ['search.msn.com'],
        'Applebot' => ['applebot.apple.com'],
        'YandexBot' => ['yandex.ru', 'yandex.net', 'yandex.com'],
        'Baiduspider' => ['baidu.com', 'baidu.jp'],
    ];

    /** Crawlers gathering pages for an AI. */
    private const AI_CRAWLERS = ['GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'ClaudeBot', 'Claude-Web', 'anthropic-ai', 'CCBot', 'PerplexityBot', 'Bytespider', 'Google-Extended', 'Amazonbot', 'meta-externalagent', 'Applebot-Extended'];

    /** A browser that is a program, not a person's: tools, libraries, automated and headless browsers. */
    private const SCRIPTS = '/curl|wget|python|requests|httpclient|okhttp|java\/|go-http|libwww|scrapy|aiohttp|axios|node-fetch|headless|phantomjs|selenium|puppeteer|playwright|zgrab|masscan|nmap|nikto|sqlmap/i';

    private const CRAWLERS = '/\b[\w.-]*(bot|spider|crawler)\b[\w.-]*|facebookexternalhit|slurp|semrush|ahrefs|preview/i';

    private function __construct(public readonly string $kind, public readonly string $label, public readonly string $device)
    {
    }

    public function isAutomated(): bool
    {
        return in_array($this->kind, [self::SCRIPT, self::SEARCH, self::CRAWLER, self::NONE], true);
    }

    public static function read(string $agent): self
    {
        $agent = trim($agent);
        if ($agent === '' || $agent === 'Unknown browser') return new self(self::NONE, 'No browser named', '');
        foreach (array_keys(self::SEARCH_ENGINES) as $engine) {
            // Applebot-Extended and the like are named among the AI crawlers below.
            if (preg_match('/\b' . $engine . '\b(?!-)/i', $agent) === 1) return new self(self::SEARCH, $engine, '');
        }
        foreach (self::AI_CRAWLERS as $crawler) {
            if (stripos($agent, $crawler) !== false) return new self(self::CRAWLER, 'AI crawler: ' . $crawler, '');
        }
        if (preg_match(self::SCRIPTS, $agent, $found, PREG_OFFSET_CAPTURE) === 1) {
            // The word of the description that gave it away, such as HeadlessChrome/156.0 or python-requests/2.32.
            $start = (int) strrpos(' ' . substr($agent, 0, $found[0][1]), ' ');
            $word = (string) strtok(substr($agent, $start) . ' ', ' ');
            return new self(self::SCRIPT, 'Script: ' . mb_substr((string) strtok($word, '/'), 0, 60), '');
        }
        if (preg_match(self::CRAWLERS, $agent, $found) === 1) return new self(self::CRAWLER, 'Crawler: ' . mb_substr($found[0], 0, 60), '');

        $device = self::device($agent);
        $system = self::system($agent);
        if (preg_match('#\bMSIE \d|\bTrident/\d#', $agent) === 1) return new self(self::OUTDATED, 'Outdated: Internet Explorer', $device);
        [$browser, $version] = self::browser($agent);
        if ($browser === '') return new self(self::BROWSER, $system === '' ? 'Another browser' : 'A browser on ' . $system, $device);
        $family = $browser === 'Edge' || $browser === 'Opera' || $browser === 'Samsung Internet' ? 'Chrome' : $browser;
        if ($version !== null && $version < (self::OLDEST[$family] ?? 0) && !in_array($browser, ['Edge', 'Opera', 'Samsung Internet'], true)) {
            return new self(self::OUTDATED, 'Outdated: ' . $browser . ' ' . $version, $device);
        }

        return new self(self::BROWSER, $system === '' ? $browser : $browser . ' on ' . $system, $device);
    }

    /** @return array{0:string,1:?int} the browser's name and major version */
    private static function browser(string $agent): array
    {
        foreach (['Edge' => '#\bEdg(?:e|A|iOS)?/(\d+)#', 'Opera' => '#\bOPR/(\d+)#', 'Samsung Internet' => '#\bSamsungBrowser/(\d+)#', 'Firefox' => '#\b(?:Firefox|FxiOS)/(\d+)#', 'Chrome' => '#\b(?:Chrome|Chromium|CriOS)/(\d+)#', 'Safari' => '#\bVersion/(\d+)[.\d]* (?:Mobile/\S+ )?Safari/#'] as $name => $pattern) {
            if (preg_match($pattern, $agent, $match) === 1) return [$name, (int) $match[1]];
        }

        return ['', null];
    }

    private static function system(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Macintosh') || str_contains($agent, 'Mac OS X') => 'Mac',
            str_contains($agent, 'CrOS') => 'a Chromebook',
            str_contains($agent, 'Linux') => 'Linux',
            default => '',
        };
    }

    private static function device(string $agent): string
    {
        if (str_contains($agent, 'iPad') || str_contains($agent, 'Tablet') || (str_contains($agent, 'Android') && !str_contains($agent, 'Mobile'))) return 'tablet';
        if (str_contains($agent, 'iPhone') || str_contains($agent, 'Mobile') || str_contains($agent, 'Android')) return 'phone';

        return 'computer';
    }
}
