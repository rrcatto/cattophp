<?php

declare(strict_types=1);

namespace CattoPHP\Document;

/**
 * What document CSS may contain. Typography, spacing, borders, tables, page breaks and backgrounds are
 * ordinary CSS and all allowed. Refused: anything that reaches outside the document (@import, url()
 * other than an embedded data: image), anything executable in some renderer (expression(), behavior,
 * -moz-binding, javascript:), closing the style element, CSS escapes that could disguise those, and
 * placeholders, because document CSS is the same for every document.
 */
final class CssPolicy
{
    public const DATA_IMAGE = 'data:image/(?:png|jpeg|gif|webp|svg\+xml)[;,]';
    /** A stored document image, by the SHA-256 of its bytes; the renderer embeds it. */
    public const ASSET = 'asset:[0-9a-f]{64}';

    /** @return list<string> */
    public function check(string $css, string $where = 'The CSS'): array
    {
        $errors = [];
        $plain = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $lower = strtolower($plain);
        if (str_contains($css, '{{') || str_contains($css, '{%')) {
            $errors[] = $where . ' cannot contain placeholders; CSS is the same for every document.';
        }
        if (preg_match('/\\\\[0-9a-f]/i', $plain) === 1) {
            $errors[] = $where . ' cannot use CSS escapes such as \\75; write the characters themselves.';
        }
        if (str_contains($lower, '@import')) {
            $errors[] = $where . ' cannot use @import. Put every rule in the template’s own CSS.';
        }
        if (str_contains($lower, '</')) {
            $errors[] = $where . ' cannot contain "</".';
        }
        foreach (['expression(' => 'expression()', 'javascript:' => 'javascript:', 'vbscript:' => 'vbscript:', 'behavior' => 'behavior', '-moz-binding' => '-moz-binding'] as $needle => $label) {
            if (str_contains(str_replace(' ', '', $lower), $needle)) {
                $errors[] = $where . ' cannot use ' . $label . '.';
            }
        }
        if (preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/is', $plain, $urls, PREG_SET_ORDER) > 0) {
            foreach ($urls as $url) {
                if (preg_match('#^' . self::DATA_IMAGE . '#i', trim($url[2])) !== 1 && preg_match('#^' . self::ASSET . '$#D', trim($url[2])) !== 1) {
                    $errors[] = $where . ' may only use url() with an embedded image (data:image/png, jpeg, gif, webp or svg+xml) or a stored document image, not ' . self::shorten(trim($url[2])) . '.';
                }
            }
        }
        return array_values(array_unique($errors));
    }

    private static function shorten(string $value): string
    {
        return '"' . (mb_strlen($value) > 40 ? mb_substr($value, 0, 37) . '…' : $value) . '"';
    }
}
