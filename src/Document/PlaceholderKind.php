<?php

declare(strict_types=1);

namespace CattoPHP\Document;

/**
 * What a placeholder's value is, which decides how the renderer formats it. A template author never
 * formats a value: money comes out in the installation's currency format from integer minor units, a date
 * in the document date format, an address as one escaped line after another.
 */
enum PlaceholderKind: string
{
    /** One line of text, HTML-escaped. */
    case Text = 'text';
    /** Text whose line breaks are kept: escaped, then each break becomes <br>. */
    case Multiline = 'multiline';
    /** array{minor:int,currency:string}, formatted from its minor units. Never a float. */
    case Money = 'money';
    /** A date or timestamp, shown as the document date in the platform's time zone. */
    case Date = 'date';
    /** list<string> of address lines: each escaped, joined with <br>. */
    case Address = 'address';
    /** An http(s) address. Escaped, and refused when it is any other scheme. */
    case Url = 'url';
    case Integer = 'integer';
    /** A decimal string such as "84.50", shown as "84.50%". */
    case Percent = 'percent';

    /**
     * Whether the renderer's output for this kind contains markup it produced itself. The value is
     * escaped either way; this only declares that the result is not plain text.
     */
    public function producesMarkup(): bool
    {
        return $this === self::Multiline || $this === self::Address;
    }

    public function label(): string
    {
        return match ($this) {
            self::Text => 'text',
            self::Multiline => 'text with line breaks',
            self::Money => 'amount',
            self::Date => 'date',
            self::Address => 'address lines',
            self::Url => 'web address',
            self::Integer => 'whole number',
            self::Percent => 'percentage',
        };
    }
}
