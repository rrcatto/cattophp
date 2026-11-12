<?php

declare(strict_types=1);

namespace CattoPHP\Inbox;

/**
 * The Inbox's fixed signs of spam: pure. From what a message shows and how it was sent, each sign present adds its points,
 * in words the Spam folder shows: the same signs the sign-up check reads (Tor, VPN and hosting networks, a throwaway or
 * mail-less email domain, a blocked country, under 3 seconds on the page, a script browser, a made-up name, many messages
 * from one address) and the message's own (links, words seen in spam, shouting, asking to be written to elsewhere, the
 * same words sent before). A signed-in account holder, or a sender whose messages were marked not spam before, counts the
 * other way. The total is held to 0 to 100.
 */
final class SpamSigns
{
    /** Each sign's points and the words the Spam folder shows for it. */
    public const SIGNS = [
        'blocked_sender' => [100, 'an address or domain you blocked'],
        'country_blocked' => [60, 'sent from a blocked country'],
        'tor' => [50, 'Tor exit address'],
        'vpn' => [25, 'VPN network'],
        'datacenter' => [30, 'hosting network'],
        'disposable' => [50, 'throwaway email domain'],
        'mail_domain_none' => [40, 'an email domain that receives no mail'],
        'too_fast' => [30, 'sent seconds after the page opened'],
        'browser_missing' => [40, 'no browser described'],
        'browser_script' => [40, 'a script, not a browser'],
        'links' => [10, 'a link'],
        'spam_words' => [10, 'words seen in spam'],
        'shouting' => [15, 'written in capitals'],
        'elsewhere' => [20, 'asks to be written to elsewhere'],
        'repeated' => [30, 'the same words sent before'],
        'many_from_address' => [20, 'many messages from one address today'],
        'odd_name' => [15, 'a made-up name'],
        'signed_in' => [-40, 'signed in with an account'],
        'known_sender' => [-30, 'earlier messages from this sender were genuine'],
    ];

    /** Phrases seen in contact-form spam, matched whole and ignoring case. */
    private const SPAM_WORDS = ['seo', 'backlinks', 'guaranteed', 'first page of google', 'page one of google', 'crypto', 'bitcoin', 'casino', 'loan offer', 'viagra',
        'click here', 'limited time', '100% free', 'make money', 'work from home', 'winner', 'congratulations', 'dear friend', 'business proposal', 'investment opportunity',
        'increase your traffic', 'web traffic', 'rank higher', 'unsubscribe', 'act now', 'risk free', 'no obligation'];

    /** Asking to be written to anywhere but the reply (an address, a messenger). */
    private const ELSEWHERE = '/\b(reply to my (private|personal) (e-?mail|address)|contact me (at|on|via)|write to me at|whats\s?app me|telegram me|t\.me\/|wa\.me\/|my (private|personal) e-?mail)\b/i';

    /**
     * The signs a message shows, each with its points and words, and their total held to 0 to 100.
     *
     * @param array{text:string,name:string,blocked_sender?:bool,country_blocked?:bool,tor?:bool,vpn?:bool,datacenter?:bool,disposable?:bool,mail_domain_receives?:?bool,seconds_on_page?:?int,agent?:string,repeated?:bool,from_address_today?:int,signed_in?:bool,known_sender?:bool} $facts
     * @return array{score:int,signs:list<array{key:string,points:int,words:string}>}
     */
    public static function judge(array $facts): array
    {
        $text = (string) $facts['text'];
        $signs = [];
        $add = static function (string $key, int $times = 1, string $words = '') use (&$signs): void {
            [$points, $default] = self::SIGNS[$key];
            $signs[] = ['key' => $key, 'points' => $points * $times, 'words' => $words !== '' ? $words : $default];
        };
        foreach (['blocked_sender', 'country_blocked', 'tor', 'disposable', 'repeated', 'signed_in', 'known_sender'] as $key) {
            if (($facts[$key] ?? false) === true) $add($key);
        }
        if (($facts['vpn'] ?? false) === true) $add('vpn');
        elseif (($facts['datacenter'] ?? false) === true) $add('datacenter');
        if (($facts['mail_domain_receives'] ?? null) === false) $add('mail_domain_none');
        if (($facts['seconds_on_page'] ?? null) !== null && (int) $facts['seconds_on_page'] < 3) $add('too_fast', 1, 'sent ' . max(0, (int) $facts['seconds_on_page']) . ' seconds after the page opened');
        $agent = (string) ($facts['agent'] ?? '');
        if ($agent === 'none') $add('browser_missing');
        elseif ($agent === 'script') $add('browser_script');
        $links = preg_match_all('#https?://|www\.#i', $text);
        if ($links > 0) $add('links', min(3, (int) $links), $links === 1 ? 'a link' : $links . ' links');
        $found = 0;
        foreach (self::SPAM_WORDS as $phrase) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}])/iu', $text) === 1) $found++;
        }
        if ($found > 0) $add('spam_words', min(3, $found), $found === 1 ? 'a phrase seen in spam' : $found . ' phrases seen in spam');
        $letters = preg_replace('/[^\p{L}]/u', '', $text) ?? '';
        if (mb_strlen($letters) >= 40 && mb_strlen(preg_replace('/[^\p{Lu}]/u', '', $letters) ?? '') / mb_strlen($letters) > 0.5) $add('shouting');
        if (preg_match(self::ELSEWHERE, $text) === 1) $add('elsewhere');
        if ((int) ($facts['from_address_today'] ?? 0) >= 3) $add('many_from_address', 1, ($facts['from_address_today'] ?? 0) . ' messages from one address today');
        if (self::oddName((string) $facts['name'])) $add('odd_name');
        $score = array_sum(array_column($signs, 'points'));
        return ['score' => max(0, min(100, $score)), 'signs' => $signs];
    }

    /** A name a person would not have: no vowel, a run of five consonants, or letters and digits mixed. */
    private static function oddName(string $name): bool
    {
        $name = mb_strtolower(trim($name));
        if ($name === '' || mb_strlen($name) < 2) return true;
        if (preg_match('/\d/', $name) === 1 && preg_match('/\p{L}/u', $name) === 1) return true;
        $letters = preg_replace('/[^a-z]/', '', $name) ?? '';
        if ($letters === '') return false;
        return preg_match('/[aeiouy]/', $letters) !== 1 || preg_match('/[bcdfghjklmnpqrstvwxz]{5,}/', $letters) === 1;
    }
}
