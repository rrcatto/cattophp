<?php

declare(strict_types=1);

namespace CattoPHP\Search;

use CattoPHP\Activity\Actor;
use CattoPHP\Settings\Settings;
use InvalidArgumentException;

/**
 * What robots.txt tells AI crawlers (AiCrawlers: all of them in, the search and answer assistants in with the
 * training-only crawlers kept out, or none of them in), and the two codes that prove to Google Search Console and Bing
 * Webmaster Tools that the site is its owner's, written into the home page's head. Three settings
 * (SearchSettingGroup), so .env may give starting values and Settings overrides them; until anything is saved every AI
 * crawler is let in.
 *
 * A code may be typed bare or pasted as the whole meta tag the service gives; either way only the code is kept.
 */
final class SearchSettings
{
    /** Let every AI crawler in: the default. */
    public const ALLOW_ALL = 'all';
    /** Let in the crawlers that fetch pages for AI search and answers, and keep out those that only collect training data. */
    public const SEARCH_ONLY = 'search';
    /** Keep every AI crawler out. */
    public const BLOCK_ALL = 'none';
    public const CHOICES = [self::ALLOW_ALL, self::SEARCH_ONLY, self::BLOCK_ALL];

    /** The settings, by the name each value has here. */
    public const NAMES = ['ai_crawlers' => 'SEARCH_AI_CRAWLERS', 'google' => 'SEARCH_GOOGLE_CODE', 'bing' => 'SEARCH_BING_CODE'];

    /** The two verification codes: the meta tag's name in the head, and what the field is called. */
    public const VERIFICATION = [
        'google' => ['meta' => 'google-site-verification', 'name' => 'The Google Search Console code'],
        'bing' => ['meta' => 'msvalidate.01', 'name' => 'The Bing Webmaster Tools code'],
    ];

    /** @param array<string,string>|null $fixed */
    public function __construct(private readonly ?Settings $settings = null, private readonly ?array $fixed = null)
    {
    }

    /** @param array{ai_crawlers?:string,google?:string,bing?:string} $values settings decided by the caller (tests and tools) */
    public static function fixed(array $values): self
    {
        return new self(null, $values);
    }

    /**
     * What is saved, or the defaults.
     *
     * @return array{ai_crawlers:string,google:string,bing:string}
     */
    public function saved(): array
    {
        $saved = $this->fixed ?? [];
        if ($this->fixed === null && $this->settings !== null) {
            foreach (self::NAMES as $key => $name) {
                $saved[$key] = $this->settings->get($name);
            }
        }
        $choice = (string) ($saved['ai_crawlers'] ?? '');

        return [
            'ai_crawlers' => in_array($choice, self::CHOICES, true) ? $choice : self::ALLOW_ALL,
            'google' => self::cleanCode((string) ($saved['google'] ?? '')),
            'bing' => self::cleanCode((string) ($saved['bing'] ?? '')),
        ];
    }

    public function aiCrawlers(): string
    {
        return $this->saved()['ai_crawlers'];
    }

    /**
     * The verification meta tags the home page's head carries: name => content, only for a code that is set.
     *
     * @return array<string,string>
     */
    public function verificationTags(): array
    {
        $saved = $this->saved();
        $tags = [];
        foreach (self::VERIFICATION as $service => $definition) {
            if ($saved[$service] !== '') {
                $tags[$definition['meta']] = $saved[$service];
            }
        }

        return $tags;
    }

    /**
     * Saves the choice and the codes, each code checked first; nothing is saved when one is wrong. Each change is in the
     * activity log as the settings' own. Returns what was saved before and after.
     *
     * @param array<string,mixed> $input ai_crawlers, google, bing
     * @return array{old:array<string,string>,new:array<string,string>}
     */
    public function save(array $input, ?Actor $actor = null): array
    {
        if ($this->settings === null) {
            throw new InvalidArgumentException('These settings are fixed.');
        }
        $choice = (string) ($input['ai_crawlers'] ?? '');
        if (!in_array($choice, self::CHOICES, true)) {
            throw new InvalidArgumentException('Choose what AI crawlers may do.');
        }
        $values = [self::NAMES['ai_crawlers'] => $choice];
        foreach (self::VERIFICATION as $service => $definition) {
            $values[self::NAMES[$service]] = self::code((string) ($input[$service] ?? ''), $definition['meta'], $definition['name']);
        }
        $before = $this->saved();
        $this->settings->save(SearchSettingGroup::GROUP, $values, $actor);

        return ['old' => $before, 'new' => $this->saved()];
    }

    /**
     * The code from what was typed: the bare code, or the content of the whole meta tag pasted in. Empty leaves it unset.
     */
    public static function code(string $typed, string $metaName, string $name): string
    {
        $typed = trim($typed);
        if ($typed === '') {
            return '';
        }
        if (str_contains($typed, '<')) {
            if (preg_match('/<meta\b[^>]*>/i', $typed, $tag) !== 1) {
                throw new InvalidArgumentException($name . ' could not be read: paste the code itself, or the whole meta tag.');
            }
            if (preg_match('/\bname\s*=\s*["\']?([^"\'\s>]+)/i', $tag[0], $named) === 1 && strcasecmp($named[1], $metaName) !== 0) {
                throw new InvalidArgumentException($name . ' is a meta tag named ' . $named[1] . '. The one to paste is named ' . $metaName . '.');
            }
            if (preg_match('/\bcontent\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag[0], $content) !== 1) {
                throw new InvalidArgumentException($name . ': the meta tag has no content. Paste the code itself, or the whole meta tag.');
            }
            $typed = trim($content[1] . ($content[2] ?? '') . ($content[3] ?? ''));
        }
        // A verification code is letters, digits, hyphens and underscores (Google's are about 43, Bing's 32).
        if (preg_match('/^[A-Za-z0-9_-]{8,100}$/D', $typed) !== 1) {
            throw new InvalidArgumentException($name . ' is not a code this site recognises: it is letters and digits only, with no spaces. Paste it again from the page that shows it.');
        }

        return $typed;
    }

    /** A saved code that is no longer a code reads as unset, rather than printing something odd into every head. */
    private static function cleanCode(string $code): string
    {
        return preg_match('/^[A-Za-z0-9_-]{8,100}$/D', $code) === 1 ? $code : '';
    }
}
