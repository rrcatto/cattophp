<?php

declare(strict_types=1);

namespace CattoPHP\Signup;

use CattoPHP\Network\NetworkFacts;
use DateTimeZone;

/**
 * Judges a registration. Each signal found adds its points and says why in plain words; the score is at most 100, and
 * its band is low (under 25), medium (under 60) or high.
 *
 * - Country: one on the blocked list 60, one outside the allowed list (or one that cannot be found while there is an
 *   allowed list) 30. A blocked country is held for approval without being told why; outside the allowed list is a
 *   warning sign only.
 * - Network: a Tor exit 50, a VPN provider 30, a hosting or server network 30.
 * - Email: a throwaway domain is refused ("Use an email address you keep."), a domain that cannot receive mail 40, an
 *   address that looks random 10.
 * - Browser: none described, or a script or automated browser, 40.
 * - Behaviour: the form sent within 3 seconds of opening 30, the browser's time zone not in the address's country 15,
 *   each other sign-up from the same address in the last day 20 (at most 60), a made-up looking name 20.
 * - The hidden field: filled in only by a bot; such a sign-up is scored 100 and shown the usual "check your email" page.
 *
 * A registration is held when its country is blocked or when it is high risk; what a hold means is the project's.
 */
final class SignupRisk
{
    public const REFUSED_DISPOSABLE = 'Use an email address you keep. Throwaway addresses cannot register.';

    /** Each signal's points. */
    public const POINTS = [
        'country_blocked' => 60, 'country_not_allowed' => 30, 'tor' => 50, 'vpn' => 30, 'datacenter' => 30,
        'disposable' => 50, 'mail_domain_none' => 40, 'email_random' => 10, 'browser_missing' => 40, 'browser_script' => 40,
        'too_fast' => 30, 'time_zone' => 15, 'same_ip' => 20, 'name_odd' => 20, 'trap' => 100,
    ];

    /** A browser that is a program, not a person's: tools, libraries, automated and headless browsers, crawlers. */
    private const SCRIPT_BROWSER = '/curl|wget|python|requests|httpclient|okhttp|java\/|go-http|libwww|scrapy|aiohttp|axios|node-fetch|headless|phantomjs|selenium|puppeteer|playwright|\bbot\b|spider|crawler/i';

    /**
     * @param list<string> $allowedCountries empty for every country
     * @param list<string> $blockedCountries
     */
    public static function assess(SignupFacts $facts, array $allowedCountries, array $blockedCountries): RiskAssessment
    {
        $signals = [];
        $hold = [];
        $add = static function (string $key, string $words, ?int $points = null) use (&$signals): void {
            $signals[] = ['key' => $key, 'points' => $points ?? self::POINTS[$key], 'words' => $words];
        };
        $network = $facts->network;
        $country = $network->countryCode;

        // Where from.
        if ($country !== '' && in_array($country, $blockedCountries, true)) {
            $add('country_blocked', 'Signed up from ' . SignupCountries::name($country) . ', a country on your blocked list.');
            $hold[] = 'country_blocked';
        } elseif ($allowedCountries !== [] && !in_array($country, $allowedCountries, true)) {
            $add('country_not_allowed', $country === ''
                ? 'The country could not be found from the IP address, so it is not one of your allowed countries.'
                : 'Signed up from ' . SignupCountries::name($country) . ', outside your allowed countries (' . SignupCountries::names($allowedCountries) . ').');
            // Being abroad is a warning sign, never a hold: someone travelling may register from anywhere.
        }
        if ($network->tor) $add('tor', 'The IP address is a Tor exit, used to hide where someone is.');
        if ($network->vpn) $add('vpn', 'The IP address belongs to a VPN provider' . self::named($network) . '.');
        elseif ($network->hosting) $add('datacenter', 'The IP address belongs to a hosting or server network' . self::named($network) . ', not a home or a phone.');

        // The email address.
        $domain = $facts->emailDomain();
        $refusal = null;
        if ($facts->disposableEmail) {
            $add('disposable', $domain . ' is a throwaway email service.');
            $refusal = self::REFUSED_DISPOSABLE;
        }
        if ($facts->mailDomainReceives === false) $add('mail_domain_none', $domain . ' cannot receive email: it has no mail server.');
        $local = strtolower((string) strstr($facts->email, '@', true));
        if (self::looksRandom($local)) $add('email_random', 'The part of the address before the @ looks random (' . $local . ').');

        // The browser and how the form was filled in.
        $agent = trim($facts->userAgent);
        if ($agent === '' || $agent === 'Unknown browser') $add('browser_missing', 'The browser sent no description of itself.');
        elseif (preg_match(self::SCRIPT_BROWSER, $agent, $found, PREG_OFFSET_CAPTURE) === 1) {
            // The word of the description that gave it away, such as HeadlessChrome/156.0 or python-requests/2.32.
            $start = (int) strrpos(' ' . substr($agent, 0, $found[0][1]), ' ');
            $word = strtok(substr($agent, $start) . ' ', ' ');
            $add('browser_script', 'The browser was a program or an automated browser, not a person\'s (' . mb_substr((string) $word, 0, 60) . ').');
        }
        if ($facts->formSeconds !== null && $facts->formSeconds < 3) {
            $add('too_fast', 'The form was sent ' . $facts->formSeconds . ' ' . ($facts->formSeconds === 1 ? 'second' : 'seconds') . ' after it opened: people take longer.');
        }
        if ($country !== '' && $facts->browserTimeZone !== '' && self::zoneOutside($facts->browserTimeZone, $country)) {
            $add('time_zone', 'The browser\'s time zone (' . $facts->browserTimeZone . ') is not in ' . SignupCountries::name($country) . ', where the IP address is.');
        }
        if ($facts->otherAttemptsFromIp > 0) {
            $add('same_ip', $facts->otherAttemptsFromIp . ' other ' . ($facts->otherAttemptsFromIp === 1 ? 'sign-up' : 'sign-ups') . ' came from the same IP address in the last 24 hours.',
                self::POINTS['same_ip'] * min(3, $facts->otherAttemptsFromIp));
        }
        $odd = self::oddName($facts->firstName, $facts->lastName);
        if ($odd !== null) $add('name_odd', 'The name looks made up: ' . $odd . '.');
        $trapped = $facts->trapFilled;
        if ($trapped) $add('trap', 'A hidden field that people never see was filled in: an automated sign-up.');

        $score = min(100, array_sum(array_column($signals, 'points')));
        $band = RiskAssessment::band($score);
        if ($band === RiskAssessment::HIGH) $hold[] = 'high_risk';

        return new RiskAssessment($score, $band, $signals, array_values(array_unique($hold)), $refusal, $trapped);
    }

    /** What each hold reason means, for the person's page. */
    public static function holdWords(string $reason): string
    {
        return [
            'country_blocked' => 'a blocked country',
            'country_not_allowed' => 'outside your allowed countries',
            'high_risk' => 'high risk',
        ][$reason] ?? $reason;
    }

    private static function named(NetworkFacts $network): string
    {
        return $network->networkName !== '' ? ' (' . $network->networkName . ')' : '';
    }

    /** A local part with almost no vowels among six or more letters, or a run of seven or more digits. */
    private static function looksRandom(string $local): bool
    {
        $local = (string) preg_replace('/\+.*$/', '', $local);
        $letters = (string) preg_replace('/[^a-z]/', '', $local);
        $vowels = (string) preg_replace('/[^aeiouy]/', '', $letters);

        return (strlen($letters) >= 6 && strlen($vowels) / strlen($letters) < 0.15) || preg_match('/\d{7,}/', $local) === 1;
    }

    private static function zoneOutside(string $zone, string $country): bool
    {
        try {
            $zones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $country);
        } catch (\Throwable) {
            return false;
        }

        return $zones !== [] && !in_array($zone, $zones, true) && $zone !== 'UTC';
    }

    /** Why a name looks made up, or null. */
    private static function oddName(string $first, string $last): ?string
    {
        $first = trim($first);
        $last = trim($last);
        $both = $first . ' ' . $last;
        if ($first !== '' && strcasecmp($first, $last) === 0) return 'the first and last names are the same';
        if (preg_match('#https?:|www\.|\.(com|net|org|ru|xyz|top)\b#i', $both) === 1) return 'it holds a web address';
        if (preg_match('/\d/', $both) === 1) return 'it holds digits';
        if (mb_strlen($both) > 60) return 'it is unusually long';
        foreach ([$first, $last] as $part) {
            if (mb_strlen($part) >= 4 && preg_match('/^[^aeiouyAEIOUY]+$/u', $part) === 1 && preg_match('/^\p{Lu}+$/u', $part) === 1) return 'a part has no vowels';
        }

        return null;
    }
}
