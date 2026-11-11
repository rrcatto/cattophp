<?php

declare(strict_types=1);

namespace CattoPHP\Ui;

use InvalidArgumentException;

/**
 * A picture uploaded as an SVG file, to be drawn inline on public pages, so it must be a well-formed SVG and nothing
 * that runs: no script, no event attribute, no embedded document and no link to anything outside the picture. A
 * document type declaration with anything inside it is refused, so no entity is ever expanded.
 *
 * Pictures from icon sites are made fit for the page:
 * - the plain public SVG document type line such sites add, with nothing inside it, is dropped, as are comments;
 * - a <style> inside the picture is scoped to it (its root gets a class svg-<hash>, every selector is prefixed with it,
 *   and the picture's own ids get the same prefix), so its rules and ids never reach other pictures on the page;
 * - an icon (at most ICON_BYTES) drawn in one colour takes the theme's colour (currentColor), one without any colour
 *   too, and loses a fixed width and height, so each place it is shown sizes it.
 *
 * A feature card's icon (the component form.picture-choice) is checked by it.
 */
final class UploadedSvg
{
    /** The largest icon accepted: an icon is a few shapes. */
    public const ICON_BYTES = 20000;
    /** The largest picture accepted (a cover, a logo): drawn wherever it is shown, and never recoloured, as only icons are. */
    public const PICTURE_BYTES = 200000;

    private const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';
    /** Elements that run, load or embed something: never part of a picture here. */
    private const REFUSED_ELEMENTS = ['script', 'foreignobject', 'iframe', 'object', 'embed', 'audio', 'video', 'handler', 'listener'];

    /**
     * The SVG an upload field carries, or null when no file was chosen.
     *
     * @param mixed $file one entry of $_FILES
     * @throws InvalidArgumentException when a file was chosen but is not an acceptable SVG
     */
    public static function fromUpload(mixed $file, int $limitBytes, string $what): ?string
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_file($file['tmp_name'])) {
            throw new InvalidArgumentException('The ' . $what . ' could not be uploaded. Choose the SVG file again.');
        }
        $size = (int) ($file['size'] ?? filesize($file['tmp_name']));
        if ($size > $limitBytes) {
            throw new InvalidArgumentException('The ' . $what . ' is too large: an SVG file of at most ' . intdiv($limitBytes, 1000) . ' KB is accepted.');
        }
        return self::validate((string) file_get_contents($file['tmp_name']), $limitBytes, $what);
    }

    /**
     * The picture as stored: the svg element alone, without an XML declaration.
     *
     * @throws InvalidArgumentException when it is not an acceptable SVG
     */
    public static function validate(string $svg, int $limitBytes, string $what): string
    {
        $svg = trim(preg_replace('/^\xEF\xBB\xBF/', '', $svg) ?? '');
        if ($svg === '' || strlen($svg) > $limitBytes) {
            throw new InvalidArgumentException('The ' . $what . ' must be an SVG file of at most ' . intdiv($limitBytes, 1000) . ' KB.');
        }
        // The plain public document type line icon sites add, with nothing inside it, says nothing: dropped.
        $svg = (string) preg_replace('/<!DOCTYPE\s+svg(?:\s+PUBLIC\s+"[^"\[\]<>]*"\s+"[^"\[\]<>]*"|\s+SYSTEM\s+"[^"\[\]<>]*")?\s*>/i', '', $svg);
        if (stripos($svg, '<!DOCTYPE') !== false || stripos($svg, '<!ENTITY') !== false) {
            throw new InvalidArgumentException('The ' . $what . ' must not declare a document type. Save it as a plain SVG and try again.');
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($svg, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $document->documentElement;
        if (!$loaded || $root === null || strtolower((string) $root->localName) !== 'svg' || !in_array($root->namespaceURI, [self::SVG_NAMESPACE, null, ''], true)) {
            throw new InvalidArgumentException('The ' . $what . ' must be an SVG file (its first element is <svg>).');
        }
        foreach ($document->getElementsByTagName('*') as $element) {
            if (in_array(strtolower((string) $element->localName), self::REFUSED_ELEMENTS, true)) {
                throw new InvalidArgumentException('The ' . $what . ' must be a picture only: it contains a <' . $element->localName . '> element.');
            }
            if (strtolower((string) $element->localName) === 'style' && preg_match('/@import|url\(\s*[\'"]?\s*(?!#)/i', (string) $element->textContent) === 1) {
                throw new InvalidArgumentException('The ' . $what . ' must not link to anything outside itself.');
            }
            foreach ($element->attributes ?? [] as $attribute) {
                $name = strtolower((string) $attribute->localName);
                $value = strtolower(trim((string) $attribute->value));
                if (str_starts_with($name, 'on')) {
                    throw new InvalidArgumentException('The ' . $what . ' must be a picture only: it has an event attribute (' . $attribute->nodeName . ').');
                }
                if (($name === 'href' || $name === 'src') && !str_starts_with($value, '#')) {
                    throw new InvalidArgumentException('The ' . $what . ' must not link to anything outside itself.');
                }
                if (str_contains($value, 'javascript:') || preg_match('/url\(\s*[\'"]?\s*(?!#)/', $value) === 1) {
                    throw new InvalidArgumentException('The ' . $what . ' must not link to anything outside itself.');
                }
            }
        }
        if ($root->namespaceURI === null || $root->namespaceURI === '') $root->setAttribute('xmlns', self::SVG_NAMESPACE);
        $xpath = new \DOMXPath($document);
        foreach (iterator_to_array($xpath->query('//comment()') ?: []) as $comment) if ($comment instanceof \DOMComment) $comment->parentNode?->removeChild($comment);
        self::scope($document, $root, $xpath);
        if ($limitBytes <= self::ICON_BYTES) self::fitIcon($root, $xpath);
        return (string) $document->saveXML($root);
    }

    /**
     * Keeps a picture's style rules and ids to itself: drawn inline, a <style> would reach every picture on the page
     * (icon sites name their classes .st0, .st1 … in every file) and two pictures could share an id.
     */
    private static function scope(\DOMDocument $document, \DOMElement $root, \DOMXPath $xpath): void
    {
        $styles = array_filter(iterator_to_array($xpath->query('//*[local-name()="style"]') ?: []), static fn (mixed $node): bool => $node instanceof \DOMElement);
        // The picture's own id names nothing inside it (icon sites write id="Layer_1" on every file): dropped.
        $root->removeAttribute('id');
        $ids = iterator_to_array($xpath->query('//*[@id]') ?: []);
        if ($styles === [] && $ids === []) return;
        if (preg_match('/(?:^|\s)svg-[0-9a-f]{10}(?:\s|$)/', $root->getAttribute('class')) === 1) return; // scoped already
        $scope = 'svg-' . substr(hash('sha256', (string) $document->saveXML($root)), 0, 10);
        $renamed = [];
        foreach ($ids as $element) {
            if (!$element instanceof \DOMElement) continue;
            $renamed[$element->getAttribute('id')] = $scope . '-' . $element->getAttribute('id');
            $element->setAttribute('id', $renamed[$element->getAttribute('id')]);
        }
        $reference = static function (string $text) use ($renamed): string {
            return (string) preg_replace_callback('/url\(\s*([\'"]?)#([^)\'"\s]+)\1\s*\)/', static fn(array $m): string => isset($renamed[$m[2]]) ? 'url(#' . $renamed[$m[2]] . ')' : $m[0], $text);
        };
        foreach (iterator_to_array($xpath->query('//@*') ?: []) as $attribute) {
            if (!$attribute instanceof \DOMAttr || $attribute->localName === 'id') continue;
            $value = $attribute->value;
            if ($attribute->localName === 'href' && str_starts_with($value, '#') && isset($renamed[substr($value, 1)])) $value = '#' . $renamed[substr($value, 1)];
            $value = $reference($value);
            if ($value !== $attribute->value) $attribute->value = $value;
        }
        foreach ($styles as $style) {
            $style->textContent = self::scopedCss($reference((string) $style->textContent), '.' . $scope, $renamed);
        }
        $root->setAttribute('class', trim($root->getAttribute('class') . ' ' . $scope));
    }

    /**
     * Every selector of a stylesheet prefixed with the picture's scope class, inside @media too; an id selector
     * follows the id's new name.
     *
     * @param array<string,string> $renamed
     */
    private static function scopedCss(string $css, string $scope, array $renamed): string
    {
        $out = '';
        $length = strlen($css);
        $at = 0;
        while ($at < $length) {
            $open = strpos($css, '{', $at);
            if ($open === false) { $out .= substr($css, $at); break; }
            $prelude = substr($css, $at, $open - $at);
            $depth = 1;
            $close = $open + 1;
            while ($close < $length && $depth > 0) {
                if ($css[$close] === '{') $depth++;
                elseif ($css[$close] === '}') $depth--;
                $close++;
            }
            $body = substr($css, $open + 1, max(0, $close - $open - 2));
            $trimmed = trim($prelude);
            if (str_starts_with($trimmed, '@media') || str_starts_with($trimmed, '@supports')) {
                $out .= $prelude . '{' . self::scopedCss($body, $scope, $renamed) . '}';
            } elseif (str_starts_with($trimmed, '@')) {
                $out .= $prelude . '{' . $body . '}';
            } else {
                $selectors = array_map(static function (string $selector) use ($scope, $renamed): string {
                    $selector = (string) preg_replace_callback('/#([A-Za-z_][\w-]*)/', static fn(array $m): string => '#' . ($renamed[$m[1]] ?? $m[1]), trim($selector));
                    return preg_match('/^(svg|:root)\b/i', $selector) === 1 ? $scope . (string) preg_replace('/^(svg|:root)/i', '', $selector) : $scope . ' ' . $selector;
                }, array_filter(explode(',', $prelude), static fn(string $part): bool => trim($part) !== ''));
                $out .= implode(',', $selectors) . '{' . $body . '}';
            }
            $at = $close;
        }
        return $out;
    }

    /**
     * An icon takes the theme's colour when it is drawn in one colour (or none, which would draw it black), and
     * no fixed size: each place that shows an icon sizes it.
     */
    private static function fitIcon(\DOMElement $root, \DOMXPath $xpath): void
    {
        $colours = [];
        $ignored = ['none', 'transparent', 'currentcolor', 'inherit', 'initial', 'unset'];
        $note = static function (string $value) use (&$colours, $ignored): void {
            $value = strtolower(trim($value));
            if ($value === '' || in_array($value, $ignored, true) || str_starts_with($value, 'url(')) return;
            $colours[$value] = true;
        };
        $declarations = '/(?<![\w-])(fill|stroke|stop-color|color)\s*:\s*([^;}"\']+)/i';
        foreach (iterator_to_array($xpath->query('//@*') ?: []) as $attribute) {
            if (!$attribute instanceof \DOMAttr) continue;
            if (in_array($attribute->localName, ['fill', 'stroke', 'stop-color', 'color'], true)) $note($attribute->value);
            if ($attribute->localName === 'style') { preg_match_all($declarations, $attribute->value, $found); foreach ($found[2] as $value) $note($value); }
        }
        foreach (iterator_to_array($xpath->query('//*[local-name()="style"]') ?: []) as $style) {
            if (!$style instanceof \DOMElement) continue;
            preg_match_all($declarations, (string) $style->textContent, $found);
            foreach ($found[2] as $value) $note($value);
        }
        if (count($colours) === 1) {
            $only = (string) array_key_first($colours);
            $swap = static fn(string $text): string => (string) preg_replace('/(?<![\w#-])' . preg_quote($only, '/') . '(?![\w-])/i', 'currentColor', $text);
            foreach (iterator_to_array($xpath->query('//@*') ?: []) as $attribute) {
                if ($attribute instanceof \DOMAttr && in_array($attribute->localName, ['fill', 'stroke', 'stop-color', 'color', 'style'], true)) $attribute->value = $swap($attribute->value);
            }
            foreach (iterator_to_array($xpath->query('//*[local-name()="style"]') ?: []) as $style) if ($style instanceof \DOMElement) $style->textContent = $swap((string) $style->textContent);
        } elseif ($colours === [] && !$root->hasAttribute('fill')) {
            $root->setAttribute('fill', 'currentColor');
        }
        $width = $root->getAttribute('width');
        $height = $root->getAttribute('height');
        if (!$root->hasAttribute('viewBox') && preg_match('/^[0-9.]+(px)?$/', $width) === 1 && preg_match('/^[0-9.]+(px)?$/', $height) === 1) {
            $root->setAttribute('viewBox', '0 0 ' . (float) $width . ' ' . (float) $height);
        }
        if ($root->hasAttribute('viewBox')) { $root->removeAttribute('width'); $root->removeAttribute('height'); }
    }
}
