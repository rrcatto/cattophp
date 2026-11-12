<?php

declare(strict_types=1);

namespace CattoPHP\Country;

/**
 * Every country, by its ISO 3166-1 alpha-2 code: its name in British English (from PHP's intl extension, when installed;
 * the code itself otherwise), a code checked as a form sends it, and every country for a drop-down, A to Z by name with
 * the countries a project wants first (its own) at the top.
 */
final class Countries
{
    /** The officially assigned ISO 3166-1 alpha-2 codes. */
    public const CODES = [
        'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW', 'AX', 'AZ', 'BA', 'BB', 'BD', 'BE',
        'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS', 'BT', 'BV', 'BW', 'BY', 'BZ', 'CA', 'CC', 'CD',
        'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN', 'CO', 'CR', 'CU', 'CV', 'CW', 'CX', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM',
        'DO', 'DZ', 'EC', 'EE', 'EG', 'EH', 'ER', 'ES', 'ET', 'FI', 'FJ', 'FK', 'FM', 'FO', 'FR', 'GA', 'GB', 'GD', 'GE', 'GF',
        'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW', 'GY', 'HK', 'HM', 'HN', 'HR', 'HT', 'HU',
        'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT', 'JE', 'JM', 'JO', 'JP', 'KE', 'KG', 'KH', 'KI', 'KM', 'KN',
        'KP', 'KR', 'KW', 'KY', 'KZ', 'LA', 'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'ME',
        'MF', 'MG', 'MH', 'MK', 'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ', 'NA',
        'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU', 'NZ', 'OM', 'PA', 'PE', 'PF', 'PG', 'PH', 'PK', 'PL', 'PM',
        'PN', 'PR', 'PS', 'PT', 'PW', 'PY', 'QA', 'RE', 'RO', 'RS', 'RU', 'RW', 'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SH', 'SI',
        'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS', 'ST', 'SV', 'SX', 'SY', 'SZ', 'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK',
        'TL', 'TM', 'TN', 'TO', 'TR', 'TT', 'TV', 'TW', 'TZ', 'UA', 'UG', 'UM', 'US', 'UY', 'UZ', 'VA', 'VC', 'VE', 'VG', 'VI',
        'VN', 'VU', 'WF', 'WS', 'YE', 'YT', 'ZA', 'ZM', 'ZW',
    ];

    /** @var array<string,list<array{code:string,name:string}>> */
    private static array $options = [];

    /** A country's name, or the code itself when it is not one. */
    public static function name(string $code): string
    {
        $code = strtoupper(trim($code));
        if (!in_array($code, self::CODES, true)) return $code;
        $name = class_exists(\Locale::class) ? (string) \Locale::getDisplayRegion('-' . $code, 'en_GB') : '';

        return $name === '' || $name === $code ? $code : str_replace('’', "'", $name);
    }

    /** The code of a country as a form sends it (any case, surrounding spaces), or null when it is not a country. */
    public static function code(mixed $value): ?string
    {
        $code = strtoupper(trim(is_scalar($value) ? (string) $value : ''));

        return in_array($code, self::CODES, true) ? $code : null;
    }

    /**
     * Every country for a drop-down: the countries given first, in their order, then the rest A to Z by name.
     *
     * @return list<array{code:string,name:string}>
     */
    public static function options(string ...$first): array
    {
        $first = array_values(array_filter(array_map(static fn(string $code): ?string => self::code($code), $first)));
        $key = implode(',', $first);
        if (isset(self::$options[$key])) return self::$options[$key];
        $rest = [];
        foreach (self::CODES as $code) if (!in_array($code, $first, true)) $rest[] = ['code' => $code, 'name' => self::name($code)];
        $collator = class_exists(\Collator::class) ? new \Collator('en_GB') : null;
        usort($rest, static fn(array $a, array $b): int => $collator !== null ? (int) $collator->compare($a['name'], $b['name']) : strcmp($a['name'], $b['name']));

        return self::$options[$key] = [...array_map(static fn(string $code): array => ['code' => $code, 'name' => self::name($code)], $first), ...$rest];
    }
}
