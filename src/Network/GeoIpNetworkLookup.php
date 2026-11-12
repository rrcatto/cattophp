<?php

declare(strict_types=1);

namespace CattoPHP\Network;

use CattoPHP\Protection\Addresses;
use GeoIp2\Database\Reader;
use Throwable;

/**
 * What an address says about a visitor, all of it looked up on the server unless a project names a web service: its
 * country and place through Geocoder (GeoIp: the GeoIP2 provider over a MaxMind country or city database, or the provider
 * a project names), the network it belongs to from the GeoLite2 ASN database (GeoLite2-ASN.mmdb, by default beside the
 * country database), and whether the published lists (ReputationLists) name it a Tor exit, a VPN network or a hosting
 * network. Each database is opened on first use, so a project can make one before its container, as a lockout gate does.
 * Needs geocoder-php/geoip2-provider.
 */
final class GeoIpNetworkLookup implements NetworkLookup
{
    private ?Reader $asn = null;

    private bool $asnOpened = false;

    /** @param string|null $asnDatabase the ASN database's full path; null for GeoLite2-ASN.mmdb beside the country database */
    public function __construct(private readonly GeoIp $geo, private readonly ?ReputationLists $lists = null, private readonly ?string $asnDatabase = null)
    {
    }

    /** A lookup from the databases' and lists' places alone, for a project that needs one before its container. */
    public static function at(string $countryDatabase, ?string $listsFolder = null, ?string $asnDatabase = null): self
    {
        return new self(new GeoIp($countryDatabase), $listsFolder !== null && $listsFolder !== '' ? new ReputationLists($listsFolder) : null, $asnDatabase);
    }

    public function facts(string $address): NetworkFacts
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return NetworkFacts::unknown($address, $this->geo->isAvailable());
        }
        [$asn, $network] = $this->network($address);
        $tor = $this->lists?->isTor($address) ?? false;
        $vpn = $this->lists?->isVpn($address) ?? false;
        $hosting = $this->lists?->isHosting($address) ?? false;

        return new NetworkFacts(
            $this->geo->countryCode($address), $asn, $network,
            Addresses::isPrivate($address) ? 'private' : NetworkFacts::kindOf($tor, $vpn, $hosting),
            $tor, $vpn, $hosting, $address, $this->geo->isAvailable(),
        );
    }

    /** @return array{geo:bool,network:bool} whether the country and network databases are there */
    public function available(): array
    {
        return ['geo' => $this->geo->isAvailable(), 'network' => $this->asnReader() !== null];
    }

    /** "Johannesburg, Gauteng, South Africa" for a page, or why there is none. */
    public function place(string $address): string
    {
        return $this->geo->place($address);
    }

    public function geo(): GeoIp
    {
        return $this->geo;
    }

    /** The block of addresses an address belongs to, from the ASN database, else its /24 (a /48 for IPv6); '' for a private one. */
    public function rangeOf(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false || Addresses::isPrivate($address)) {
            return '';
        }
        $reader = $this->asnReader();
        if ($reader !== null) {
            try {
                $network = (string) $reader->asn($address)->network;
                if ($network !== '') {
                    return $network;
                }
            } catch (Throwable) {
            }
        }
        $packed = (string) inet_pton($address);

        return strlen($packed) === 4
            ? inet_ntop(substr($packed, 0, 3) . "\0") . '/24'
            : inet_ntop(substr($packed, 0, 6) . str_repeat("\0", 10)) . '/48';
    }

    /** The ASN database, opened once, or null when it is not there. */
    private function asnReader(): ?Reader
    {
        if (!$this->asnOpened) {
            $this->asnOpened = true;
            $country = $this->geo->database();
            $file = $this->asnDatabase ?? ($country !== '' ? dirname($country) . '/GeoLite2-ASN.mmdb' : '');
            try {
                if ($file !== '' && is_readable($file)) {
                    $this->asn = new Reader($file);
                }
            } catch (Throwable) {
                $this->asn = null;
            }
        }

        return $this->asn;
    }

    /** @return array{0:?int,1:string} the network's number and name, when the ASN database is there */
    private function network(string $address): array
    {
        $reader = $this->asnReader();
        if ($reader === null) {
            return [null, ''];
        }
        try {
            $record = $reader->asn($address);

            return [$record->autonomousSystemNumber, (string) $record->autonomousSystemOrganization];
        } catch (Throwable) {
            return [null, ''];
        }
    }
}
