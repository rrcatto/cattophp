<?php

declare(strict_types=1);

namespace CattoPHP\Foundation;

/**
 * RFC 4648 Base32, the alphabet authenticator apps take a key in. Decoding forgives what a person types: small
 * letters, spaces, hyphens and padding.
 */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $text = '';
        foreach (str_split($bits, 5) as $chunk) {
            $text .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $text;
    }

    public static function decode(string $text): string
    {
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('/[\s=-]/', '', $text) ?? '')) as $letter) {
            $position = strpos(self::ALPHABET, $letter);
            if ($position === false) {
                continue;
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int) bindec($chunk) & 0xFF);
            }
        }

        return $bytes;
    }
}
