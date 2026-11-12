<?php

declare(strict_types=1);

namespace CattoPHP\Search;

/**
 * Text as a search result, a share card or llms.txt shows it: one line of plain words, and an excerpt cut at a word.
 */
final class PlainText
{
    /** One line of plain text: tags removed, entities read, spaces run together. */
    public static function plain(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** Text on one line, as it is (nothing removed): spaces and line breaks run together. */
    public static function line(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Plain text from HTML or words, at most $max characters: a block's end is a space, tags go, entities are read, and a
     * longer text is cut at its last space past the half (else at $max) with an ellipsis.
     */
    public static function excerpt(string $html, int $max): string
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6])\b[^>]*>/i', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $max / 2 ? mb_substr($cut, 0, $space) : $cut, " ,.;:-") . '…';
    }
}
