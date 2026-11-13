<?php

declare(strict_types=1);

namespace CattoPHP\Installation;

use CattoPHP\Settings\Settings;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * What this installation is: its name, the organisation behind it, its public address, its administrator's email
 * address, its time zone and its locale, each a setting (APP_NAME, APP_ORGANISATION, APP_URL, APP_ADMIN_EMAIL,
 * APP_TIMEZONE, APP_LOCALE). Every
 * time a person reads is shown in its time zone. Nothing of any installation is written into the code: it names itself
 * through these.
 */
final class Installation
{
    /** The locale when the installation names none: plain British English. */
    public const LOCALE = 'en_GB';

    /** @var array<string,string>|null every time zone's name PHP knows, by its name in small letters */
    private static ?array $timeZoneNames = null;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function name(): string
    {
        return $this->settings->get('APP_NAME');
    }

    public function organisation(): string
    {
        return $this->settings->get('APP_ORGANISATION');
    }

    /** The public address, without a closing slash, such as https://example.com; '' when it is not set. */
    public function address(): string
    {
        return rtrim($this->settings->get('APP_URL'), '/');
    }

    /** A full address for a path on this site, such as https://example.com/sign-in. */
    public function addressOf(string $path): string
    {
        return $this->address() . '/' . ltrim($path, '/');
    }

    /** The host name of the public address, such as example.com. */
    public function domain(): string
    {
        return strtolower((string) parse_url($this->address(), PHP_URL_HOST));
    }

    public function administratorEmail(): string
    {
        return $this->settings->get('APP_ADMIN_EMAIL');
    }

    /**
     * The installation's locale (APP_LOCALE), such as en_GB or en_ZA: how amounts are written and the language its pages
     * say they are in. LOCALE when what is set is not a locale.
     */
    public function locale(): string
    {
        $locale = str_replace('-', '_', trim($this->settings->get('APP_LOCALE')));

        return preg_match('/^[a-z]{2,3}(_[A-Za-z]{2})?$/', $locale) === 1 ? $locale : self::LOCALE;
    }

    /**
     * The language the pages are in, as HTML's lang and a page's head name it (BCP 47): the locale with a hyphen and its
     * region in capitals, so en_ZA is en-ZA and en_GB unless the installation says otherwise is en-GB.
     */
    public function language(): string
    {
        [$language, $region] = explode('_', $this->locale()) + [1 => ''];

        return $region === '' ? $language : $language . '-' . strtoupper($region);
    }

    /** The installation's time zone (APP_TIMEZONE), UTC when it names none that PHP knows. */
    public function timeZone(): DateTimeZone
    {
        return $this->chosenTimeZone() ?? new DateTimeZone('UTC');
    }

    /**
     * The time zone the installation names, as PHP knows it; null when APP_TIMEZONE is not one, or cannot be read (a
     * database not reached yet), so whatever set PHP's time zone before is left as it is (PhpTimeZone).
     */
    public function chosenTimeZone(): ?DateTimeZone
    {
        try {
            $name = self::timeZoneName($this->settings->get('APP_TIMEZONE'));

            return $name === null ? null : new DateTimeZone($name);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A time zone's name as PHP lists it, whatever its case, or null when PHP knows no time zone by that name. Every name
     * PHP knows counts, the older ones kept for backward compatibility among them (Etc/UTC, Asia/Calcutta, Europe/Kiev,
     * US/Eastern, GB), so a name that worked before still works; an offset such as +02:00 is no time zone's name.
     */
    public static function timeZoneName(string $typed): ?string
    {
        if (self::$timeZoneNames === null) {
            self::$timeZoneNames = [];
            foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC) as $name) {
                self::$timeZoneNames[strtolower($name)] = $name;
            }
        }

        return self::$timeZoneNames[strtolower(trim($typed))] ?? null;
    }

    /** A moment in this installation's time zone. */
    public function localTime(DateTimeInterface $at): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($at)->setTimezone($this->timeZone());
    }
}
