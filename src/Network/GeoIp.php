<?php

declare(strict_types=1);

namespace CattoPHP\Network;

use Geocoder\Formatter\StringFormatter;
use Geocoder\Provider\GeoIP2\GeoIP2;
use Geocoder\Provider\GeoIP2\GeoIP2Adapter;
use Geocoder\Provider\Provider;
use Geocoder\Query\GeocodeQuery;
use GeoIp2\Database\Reader;
use Throwable;

/**
 * Best-effort geolocation of an address through Geocoder PHP (geocoder-php/geoip2-provider): its place, such as
 * "Johannesburg, Gauteng, 🇿🇦 South Africa", and its country. By default the provider is Geocoder's GeoIP2 provider
 * over a local MaxMind country or city database (GeoLite2-City.mmdb); a project names another Geocoder provider in
 * configuration (`network.geocoder`), such as a web service or a chain that falls back from one provider to the next,
 * and nothing else changes. A missing database, a private address or a failed lookup never breaks a page: the caller
 * gets a short label instead.
 */
final class GeoIp
{
    private ?Reader $reader = null;

    private ?Provider $geocoder;

    private bool $opened = false;

    /**
     * @param string $database the MaxMind database's full path; '' for none
     * @param Provider|null $provider a Geocoder provider in place of the GeoIP2 provider over that database
     */
    public function __construct(private readonly string $database = '', private readonly ?Provider $provider = null)
    {
        $this->geocoder = $provider;
    }

    public function database(): string
    {
        return $this->database;
    }

    public function isAvailable(): bool
    {
        $this->open();

        return $this->geocoder !== null;
    }

    /** The two-letter country of a public address, or '' when it is private, unknown or there is nothing to ask. */
    public function countryCode(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return '';
        }
        $this->open();
        // The database itself when the provider is the default one over it; the provider a project named otherwise.
        if ($this->provider === null && $this->reader !== null) {
            try {
                $type = $this->reader->metadata()->databaseType;
                $code = str_contains($type, 'City') ? $this->reader->city($address)->country->isoCode : $this->reader->country($address)->country->isoCode;

                return is_string($code) ? strtoupper($code) : '';
            } catch (Throwable) {
                return '';
            }
        }
        if ($this->geocoder === null) {
            return '';
        }
        try {
            $code = $this->geocoder->geocodeQuery(GeocodeQuery::create($address))->first()->getCountry()?->getCode();

            return is_string($code) ? strtoupper($code) : '';
        } catch (Throwable) {
            return '';
        }
    }

    /** "Johannesburg, Gauteng, 🇿🇦 South Africa" for a page, or why there is none. */
    public function place(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return 'Invalid IP';
        }
        $publicAddress = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if ($publicAddress === false) {
            return 'Local/private';
        }
        $this->open();
        if ($this->geocoder === null) {
            return 'GeoIP unavailable';
        }
        try {
            $found = $this->geocoder->geocodeQuery(GeocodeQuery::create($address))->first();
            $formatter = new StringFormatter();

            $locality = trim($formatter->format($found, '%L'));
            $adminLevel = trim($formatter->format($found, '%A1'));
            $country = trim($formatter->format($found, '%C'));
            $countryCode = strtoupper(trim($formatter->format($found, '%c')));
            $flag = self::flag($countryCode);

            $countryLabel = trim(($flag !== '' ? $flag . ' ' : '') . $country);
            $parts = array_values(array_unique(array_filter([$locality, $adminLevel, $countryLabel], static fn(string $part): bool => $part !== '')));

            return $parts !== [] ? implode(', ', $parts) : 'Location unavailable';
        } catch (Throwable) {
            return 'Location unavailable';
        }
    }

    /** A two-letter country code's flag, drawn by its two regional indicator letters; '' for anything else. */
    public static function flag(string $countryCode): string
    {
        $countryCode = strtoupper(trim($countryCode));
        if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            return '';
        }
        $base = 0x1F1E6;

        return mb_chr($base + ord($countryCode[0]) - 65, 'UTF-8') . mb_chr($base + ord($countryCode[1]) - 65, 'UTF-8');
    }

    /**
     * The database and the GeoIP2 provider over it, opened once on first use (a project may make this before its
     * container), unless a project named its own provider.
     */
    private function open(): void
    {
        if ($this->opened) {
            return;
        }
        $this->opened = true;
        if ($this->database === '' || !is_file($this->database) || !is_readable($this->database)) {
            return;
        }
        try {
            $this->reader = new Reader($this->database, ['en']);
            $this->geocoder ??= new GeoIP2(new GeoIP2Adapter($this->reader));
        } catch (Throwable) {
            $this->reader = null;
            $this->geocoder = $this->provider;
        }
    }
}
