<?php

declare(strict_types=1);

namespace CattoPHP\Network;

use CattoPHP\Protection\Addresses;

/**
 * What is known of an address's network: its country, its owner, and what kind of network it is. An address can be
 * on more than one list (a Tor exit inside a hosting network), so each list has its own answer beside the one kind
 * the Site Log shows.
 */
final class NetworkFacts
{
    public const KINDS = ['home', 'hosting', 'vpn', 'tor', 'private', ''];

    /**
     * @param string $kind home (a home or mobile line), hosting, vpn, tor, private, or '' when nothing is known
     * @param bool $geoAvailable whether the country database was there to ask
     */
    public function __construct(
        public readonly string $countryCode = '',
        public readonly ?int $asn = null,
        public readonly string $networkName = '',
        public readonly string $kind = '',
        public readonly bool $tor = false,
        public readonly bool $vpn = false,
        public readonly bool $hosting = false,
        public readonly string $address = '',
        public readonly bool $geoAvailable = true,
    ) {
    }

    /** An address that is private (a test site, a local network, a proxy not passing it on) or could not be read. */
    public static function unknown(string $address = '', bool $geoAvailable = true): self
    {
        return new self(kind: Addresses::isPrivate($address) ? 'private' : '', address: $address, geoAvailable: $geoAvailable);
    }

    /** Whether the address is a public one, so its country and network could be asked about. */
    public function isPublic(): bool
    {
        return !in_array($this->kind, ['private', ''], true);
    }

    /** The one kind a public address's lists make it: Tor before a VPN, a VPN before hosting, else a home or mobile line. */
    public static function kindOf(bool $tor, bool $vpn, bool $hosting): string
    {
        return $tor ? 'tor' : ($vpn ? 'vpn' : ($hosting ? 'hosting' : 'home'));
    }
}
