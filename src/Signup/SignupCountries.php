<?php

declare(strict_types=1);

namespace CattoPHP\Signup;

use CattoPHP\Activity\Actor;
use CattoPHP\Settings\EnvironmentValues;
use CattoPHP\Settings\Settings;
use InvalidArgumentException;

/**
 * The countries a sign-up is judged by: two lists of two-letter country codes.
 *
 * - Allowed: a registration from any other country (or one whose country cannot be found) is a warning sign. Empty:
 *   every country is allowed.
 * - Blocked: held for approval without being told why, and marked red. A blocked country is never turned away with a
 *   message.
 *
 * .env gives the starting lists (SIGNUP_ALLOWED_COUNTRIES and SIGNUP_BLOCKED_COUNTRIES, commas) and Settings overrides
 * them, with a Reset back to .env. Country names come from the intl extension, the code standing in without it.
 */
class SignupCountries
{
    public const ENV_ALLOWED = 'SIGNUP_ALLOWED_COUNTRIES';
    public const ENV_BLOCKED = 'SIGNUP_BLOCKED_COUNTRIES';

    /** @param Settings|null $settings null reads .env alone */
    public function __construct(private readonly ?Settings $settings = null, private readonly EnvironmentValues $environment = new EnvironmentValues())
    {
    }

    /** @return list<string> the allowed countries' codes, empty for every country */
    public function allowed(): array
    {
        return self::codes($this->settings?->get(self::ENV_ALLOWED) ?? $this->environment->get(self::ENV_ALLOWED));
    }

    /** @return list<string> the blocked countries' codes */
    public function blocked(): array
    {
        return self::codes($this->settings?->get(self::ENV_BLOCKED) ?? $this->environment->get(self::ENV_BLOCKED));
    }

    /** 'database' when Settings saved the lists, else 'environment'. */
    public function source(): string
    {
        return $this->settings?->isOverridden(self::ENV_ALLOWED) || $this->settings?->isOverridden(self::ENV_BLOCKED) ? 'database' : 'environment';
    }

    /** @return array{allowed:list<string>,blocked:list<string>} what .env says, which Reset returns to */
    public function environmentLists(): array
    {
        return [
            'allowed' => self::codes($this->settings?->envValue(self::ENV_ALLOWED) ?? $this->environment->get(self::ENV_ALLOWED)),
            'blocked' => self::codes($this->settings?->envValue(self::ENV_BLOCKED) ?? $this->environment->get(self::ENV_BLOCKED)),
        ];
    }

    /**
     * Saves both lists, which then override .env: two-letter codes separated by commas or spaces, such as ZA or
     * RU, IR, KP. A country cannot be on both.
     *
     * @return array{allowed:list<string>,blocked:list<string>}
     */
    public function save(string $allowed, string $blocked, ?Actor $actor = null): array
    {
        if ($this->settings === null) {
            throw new InvalidArgumentException('Settings cannot be saved here.');
        }
        $lists = ['allowed' => self::checked($allowed), 'blocked' => self::checked($blocked)];
        $both = array_values(array_intersect($lists['allowed'], $lists['blocked']));
        if ($both !== []) {
            throw new InvalidArgumentException(self::name($both[0]) . ' is on both lists. Put it on one.');
        }
        $this->settings->set(self::ENV_ALLOWED, implode(',', $lists['allowed']), $actor);
        $this->settings->set(self::ENV_BLOCKED, implode(',', $lists['blocked']), $actor);

        return $lists;
    }

    /** Removes what Settings saved: the lists follow .env again. */
    public function reset(): void
    {
        $this->settings?->reset(self::ENV_ALLOWED);
        $this->settings?->reset(self::ENV_BLOCKED);
    }

    /** "South Africa"; the code itself where the intl extension is missing or does not know it. */
    public static function name(string $code): string
    {
        $code = strtoupper(trim($code));
        if (function_exists('locale_get_display_region')) {
            $name = (string) locale_get_display_region('-' . $code, 'en');
            if ($name !== '' && $name !== $code && strcasecmp($name, 'Unknown Region') !== 0) {
                return $name;
            }
        }

        return $code;
    }

    /**
     * "South Africa (ZA), Namibia (NA)", or $none for an empty list.
     *
     * @param list<string> $codes
     */
    public static function names(array $codes, string $none = 'none'): string
    {
        return $codes === [] ? $none : implode(', ', array_map(static fn(string $code): string => self::name($code) . ' (' . $code . ')', $codes));
    }

    /** @return list<string> the codes in a text, upper case, each once; anything that is not two letters left out */
    private static function codes(string $text): array
    {
        $codes = [];
        foreach (preg_split('/[\s,;]+/', strtoupper($text)) ?: [] as $entry) {
            if (preg_match('/^[A-Z]{2}$/', $entry) === 1) {
                $codes[] = $entry;
            }
        }

        return array_values(array_unique($codes));
    }

    /** @return list<string> the codes typed, each checked */
    private static function checked(string $text): array
    {
        foreach (preg_split('/[\s,;]+/', strtoupper(trim($text))) ?: [] as $entry) {
            if ($entry === '') {
                continue;
            }
            if (preg_match('/^[A-Z]{2}$/', $entry) !== 1 || (function_exists('locale_get_display_region') && self::name($entry) === $entry)) {
                throw new InvalidArgumentException('"' . $entry . '" is not a country code. Use two letters, such as ZA for South Africa.');
            }
        }

        return self::codes($text);
    }
}
