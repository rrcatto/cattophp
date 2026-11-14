<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

use CattoPHP\Search\PlainText;
use InvalidArgumentException;

/**
 * The text rules for landing page copy. Copy is plain text: a line (a headline, a heading, a button's label) or prose
 * (paragraphs separated by an empty line), never HTML, so nothing an author types can become markup, a script or a style.
 * Control characters are dropped, invalid UTF-8 and over-long text are refused naming the field, a row with one of its
 * fields filled is refused, and prose is split into paragraphs for the page, which escapes every one of them.
 *
 * Each rule takes the field's label for its message and, optionally, the form field's name: given one, a refusal is a
 * CopyRefused naming it, so the editor can show the reason beside the field.
 */
final class LandingCopy
{
    private function __construct()
    {
    }

    /** One line of text: whitespace collapsed, at most $max characters. */
    public static function line(mixed $value, int $max, string $label, ?string $field = null): string
    {
        $text = self::text($value, $label, $field);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) > $max) {
            throw self::refused(sprintf('%s is longer than %d characters.', $label, $max), $field);
        }

        return $text;
    }

    /** Paragraphs: line breaks kept, runs of empty lines reduced to one, at most $max characters. */
    public static function prose(mixed $value, int $max, string $label, ?string $field = null): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", self::text($value, $label, $field));
        $lines = array_map(static fn(string $line): string => trim((string) preg_replace('/[^\S\n]+/u', ' ', $line)), explode("\n", $text));
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
        if (mb_strlen($text) > $max) {
            throw self::refused(sprintf('%s is longer than %d characters.', $label, $max), $field);
        }

        return $text;
    }

    /**
     * A list of short statements: one a line of a text box, or already a list. Empty lines are dropped.
     *
     * @return list<string>
     */
    public static function lines(mixed $value, int $maxItems, int $maxLength, string $label, ?string $field = null): array
    {
        $values = is_array($value) ? array_values($value) : explode("\n", str_replace(["\r\n", "\r"], "\n", self::text($value, $label, $field)));
        $items = [];
        foreach ($values as $number => $item) {
            $line = self::line($item, $maxLength, sprintf('%s line %d', $label, $number + 1), $field);
            if ($line !== '') {
                $items[] = $line;
            }
        }
        if (count($items) > $maxItems) {
            throw self::refused(sprintf('%s has %d lines; at most %d fit on the page.', $label, count($items), $maxItems), $field);
        }

        return $items;
    }

    /**
     * Rows of fields, such as a question and its answer. A row left empty is dropped; a row with only some of its fields
     * is refused, because half a row on the page is a mistake.
     *
     * @param array<string,array{0:string,1:int,2:bool}> $fields name => [label, max length, prose]
     * @return list<array<string,string>>
     */
    public static function rows(mixed $value, array $fields, int $maxItems, string $label, ?string $field = null): array
    {
        $rows = [];
        foreach (is_array($value) ? array_values($value) : [] as $number => $row) {
            $row = is_array($row) ? $row : [];
            $clean = [];
            foreach ($fields as $name => [$fieldLabel, $max, $prose]) {
                $name = (string) $name;
                $title = sprintf('%s %d: %s', $label, $number + 1, $fieldLabel);
                $named = $field === null ? null : $field . '.' . $number . '.' . $name;
                $clean[$name] = $prose ? self::prose($row[$name] ?? '', $max, $title, $named) : self::line($row[$name] ?? '', $max, $title, $named);
            }
            $filled = array_filter($clean, static fn(string $text): bool => $text !== '');
            if ($filled === []) {
                continue;
            }
            if (count($filled) !== count($clean)) {
                $empty = array_keys(array_diff_key($clean, $filled));
                throw self::refused(sprintf('%s %d needs its %s as well.', $label, count($rows) + 1, mb_strtolower($fields[$empty[0]][0])),
                    $field === null ? null : $field . '.' . $number . '.' . $empty[0]);
            }
            $rows[] = $clean;
        }
        if (count($rows) > $maxItems) {
            throw self::refused(sprintf('%s has %d entries; at most %d fit on the page.', $label, count($rows), $maxItems), $field);
        }

        return $rows;
    }

    /**
     * Prose as paragraphs for the page: each paragraph a list of its lines.
     *
     * @return list<list<string>>
     */
    public static function paragraphs(string $prose): array
    {
        $paragraphs = [];
        foreach (preg_split("/\n{2,}/", trim($prose)) ?: [] as $paragraph) {
            if (trim($paragraph) !== '') {
                $paragraphs[] = explode("\n", trim($paragraph));
            }
        }

        return $paragraphs;
    }

    /** HTML (a description an offer gives) as one line of plain text, cut at a word. */
    public static function excerpt(string $html, int $max): string
    {
        return PlainText::excerpt($html, $max);
    }

    private static function text(mixed $value, string $label, ?string $field): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value) && !is_int($value)) {
            throw self::refused(sprintf('%s must be text.', $label), $field);
        }
        $text = (string) $value;
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw self::refused(sprintf('%s contains characters that could not be read.', $label), $field);
        }

        // Control characters other than line breaks and tabs never belong in page copy.
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    }

    private static function refused(string $message, ?string $field): InvalidArgumentException
    {
        return $field === null ? new InvalidArgumentException($message) : new CopyRefused($field, $message);
    }
}
