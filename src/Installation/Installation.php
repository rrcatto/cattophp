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
 * address and its time zone, each a setting (APP_NAME, APP_ORGANISATION, APP_URL, APP_ADMIN_EMAIL, APP_TIMEZONE). Every
 * time a person reads is shown in its time zone. Nothing of any installation is written into the code: it names itself
 * through these.
 */
final class Installation
{
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

    public function timeZone(): DateTimeZone
    {
        try {
            return new DateTimeZone($this->settings->get('APP_TIMEZONE'));
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    /** A moment in this installation's time zone. */
    public function localTime(DateTimeInterface $at): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($at)->setTimezone($this->timeZone());
    }
}
