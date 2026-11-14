<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

/**
 * An amount of money as a person types it (1200, 1 200, 1,200.50, R1 200,50) read as whole minor units (cents), for a
 * budget, a publication's cost or other spend. A currency sign or code and spaces are ignored; when both a comma and a
 * point are typed the last is the decimal mark, and a lone comma with two digits after it is one too.
 */
final class TypedMoney
{
    /** The most an amount may be: a thousand billion in the currency. */
    public const LARGEST = 100_000_000_000_000;

    private function __construct()
    {
    }

    /** The amount in minor units, or null when what was typed is not an amount of money of zero or more. */
    public static function minor(string $typed): ?int
    {
        $text = (string) preg_replace('/[\s\x{00a0}\x{202f}\']+/u', '', $typed);
        $text = (string) preg_replace('/^[^\d.,-]{0,4}|[^\d.,]{0,4}$/u', '', $text);
        if ($text === '' || preg_match('/^\d[\d.,]*$|^[.,]\d+$/', $text) !== 1) {
            return null;
        }
        $comma = strrpos($text, ',');
        $point = strrpos($text, '.');
        $decimal = null;
        if ($comma !== false && $point !== false) {
            $decimal = max($comma, $point);
        } elseif ($point !== false && substr_count($text, '.') === 1 && strlen($text) - $point - 1 <= 2) {
            $decimal = $point;
        } elseif ($comma !== false && substr_count($text, ',') === 1 && strlen($text) - $comma - 1 <= 2) {
            $decimal = $comma;
        }
        $whole = $decimal === null ? $text : substr($text, 0, $decimal);
        $fraction = $decimal === null ? '' : substr($text, $decimal + 1);
        // Marks between thousands come between groups of three digits, as in 1,200,000.
        if ($whole !== '' && preg_match('/^\d+$|^\d{1,3}([.,]\d{3})+$/', $whole) !== 1) {
            return null;
        }
        $whole = str_replace([',', '.'], '', $whole);
        if (preg_match('/^\d*$/', $whole) !== 1 || preg_match('/^\d{0,2}$/', $fraction) !== 1 || ($whole === '' && $fraction === '') || strlen($whole) > 13) {
            return null;
        }
        $minor = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');

        return $minor <= self::LARGEST ? $minor : null;
    }

    /** An amount in minor units as it is typed back into a form: 1200.50, or 1200 when it has no cents. */
    public static function typed(?int $minor): string
    {
        if ($minor === null) {
            return '';
        }

        return $minor % 100 === 0 ? (string) intdiv($minor, 100) : sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }
}
