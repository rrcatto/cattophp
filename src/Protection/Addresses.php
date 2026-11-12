<?php

declare(strict_types=1);

namespace CattoPHP\Protection;

/**
 * Internet addresses as site protection counts them: an IPv6 machine is its /64 (one household or server holds a whole
 * /64, so counting single IPv6 addresses would let it walk round every rule), a private or loopback address is never a
 * stranger, and a range holds the addresses it covers.
 */
final class Addresses
{
    /** The address the rules count and a lockout holds: the address, or an IPv6 address's /64. */
    public static function subject(string $address): string
    {
        $packed = @inet_pton($address);
        if ($packed === false) return $address;
        if (strlen($packed) === 4) return (string) inet_ntop($packed);

        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /** A private or loopback address: a local test site, or a proxy not passing the visitor's address on. Never timed out, or everyone would be. */
    public static function isPrivate(string $address): bool
    {
        $address = (string) strtok($address, '/');
        if (filter_var($address, FILTER_VALIDATE_IP) === false) return false;
        if ($address === '::1' || str_starts_with($address, '127.')) return true;

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;
    }

    /** Whether a range holds an address, or an address is that address. */
    public static function contains(string $range, string $address): bool
    {
        [$network, $bits] = array_pad(explode('/', trim($range), 2), 2, null);
        $packedNetwork = @inet_pton((string) $network);
        $packedAddress = @inet_pton((string) strtok($address, '/'));
        if ($packedNetwork === false || $packedAddress === false || strlen($packedNetwork) !== strlen($packedAddress)) return false;
        $bits = $bits === null ? strlen($packedNetwork) * 8 : (int) $bits;
        $whole = intdiv($bits, 8);
        if (substr($packedNetwork, 0, $whole) !== substr($packedAddress, 0, $whole)) return false;
        $rest = $bits % 8;
        if ($rest === 0) return true;
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packedNetwork[$whole]) & $mask) === (ord($packedAddress[$whole]) & $mask);
    }

    /** An address, or a range such as 198.51.100.0/24 or 2001:db8::/32. */
    public static function isAddressOrRange(string $text): bool
    {
        [$address, $bits] = array_pad(explode('/', $text, 2), 2, null);
        if (filter_var($address, FILTER_VALIDATE_IP) === false) return false;
        if ($bits === null) return true;
        $most = str_contains((string) $address, ':') ? 128 : 32;

        return ctype_digit($bits) && (int) $bits >= 1 && (int) $bits <= $most;
    }

    /**
     * The addresses and ranges in a list as .env writes it (one to a line, or with commas or spaces between), leaving out
     * anything that is neither.
     *
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $text) ?: []), static fn(string $line): bool => $line !== '' && self::isAddressOrRange($line)));
    }

    /** Whether a list, as .env writes it, holds an address. */
    public static function listHolds(string $list, string $address): bool
    {
        foreach (self::lines($list) as $entry) {
            if (self::contains($entry, $address)) return true;
        }

        return false;
    }
}
