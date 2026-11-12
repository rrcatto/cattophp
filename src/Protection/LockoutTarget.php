<?php

declare(strict_types=1);

namespace CattoPHP\Protection;

use InvalidArgumentException;

/** What a lockout keeps off the site: an address (an IPv6 one as its /64), a range, or a network owner by its number. */
final class LockoutTarget
{
    public const ADDRESS = 'address';
    public const RANGE = 'range';
    public const OWNER = 'owner';

    private function __construct(
        public readonly string $kind,
        public readonly ?string $network,
        public readonly ?int $asn,
        public readonly string $label,
    ) {
    }

    public static function address(string $address): self
    {
        $subject = Addresses::subject($address);

        return new self(self::ADDRESS, $subject, null, $subject);
    }

    /**
     * Reads what a person typed: an address, a range such as 198.51.100.0/24, or a network owner's number such as AS64500.
     * A range is put on its boundary (198.51.100.7/24 is 198.51.100.0/24) and is no wider than /8, or /24 for IPv6.
     */
    public static function read(string $text, string $ownerName = ''): self
    {
        $text = trim($text);
        if (preg_match('/^AS(\d{1,10})$/i', $text, $match) === 1) {
            return new self(self::OWNER, null, (int) $match[1], $ownerName !== '' ? $ownerName : 'AS' . $match[1]);
        }
        if (filter_var($text, FILTER_VALIDATE_IP) !== false) {
            return self::address($text);
        }
        if (Addresses::isAddressOrRange($text) && str_contains($text, '/')) {
            [$address, $bits] = explode('/', $text, 2);
            $v6 = str_contains($address, ':');
            if ((int) $bits < ($v6 ? 24 : 8)) throw new InvalidArgumentException('That range is too wide. The widest allowed is /8, or /24 for IPv6.');
            $packed = (string) inet_pton($address);
            $whole = intdiv((int) $bits, 8);
            $rest = (int) $bits % 8;
            $network = substr($packed, 0, $whole);
            if ($rest > 0) $network .= chr(ord($packed[$whole]) & ((0xFF << (8 - $rest)) & 0xFF));
            $network = str_pad($network, strlen($packed), "\0");
            $range = inet_ntop($network) . '/' . $bits;
            if ($v6 ? (int) $bits === 128 : (int) $bits === 32) return new self(self::ADDRESS, $range, null, (string) inet_ntop($network));

            return new self(self::RANGE, $range, null, $range);
        }

        throw new InvalidArgumentException('Give an address, a range such as 198.51.100.0/24, or a network owner\'s number such as AS64500.');
    }

    /** @return array{kind:string,network:?string,asn:?int,label:string} */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'network' => $this->network, 'asn' => $this->asn, 'label' => $this->label];
    }
}
