<?php

declare(strict_types=1);

namespace CattoPHP\SouthAfrica;

/** South Africa's nine provinces, A to Z, and a province as a form sends it. */
final class Provinces
{
    public const ALL = ['Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo', 'Mpumalanga', 'North West', 'Northern Cape', 'Western Cape'];

    /** A province's name as the list writes it, or null when it is not one (any case, surrounding spaces). */
    public static function province(mixed $value): ?string
    {
        $text = mb_strtolower(trim(is_scalar($value) ? (string) $value : ''));
        foreach (self::ALL as $province) if (mb_strtolower($province) === $text) return $province;

        return null;
    }
}
