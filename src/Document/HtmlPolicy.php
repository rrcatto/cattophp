<?php

declare(strict_types=1);

namespace CattoPHP\Document;

use Dom\HTMLDocument;

/**
 * What document template HTML may contain. A template is the body of a document, written by a trusted
 * administrator, so ordinary document markup is all allowed: headings, paragraphs, tables, sections,
 * divs, inline elements, images and SVG. What is refused is anything executable or active, and
 * anything that makes a document depend on something outside itself:
 *
 * - script, style, iframe, object, embed, forms, media, link, meta, base and the like;
 * - event-handler attributes (onclick, onerror, ...) and javascript:/vbscript: addresses;
 * - images, backgrounds and SVG references other than an embedded data: image, a stored document
 *   image (asset:<sha256>, immutable and embedded by the renderer) or a #fragment, so a document can
 *   be reproduced exactly however old it is and nothing is fetched to render it;
 * - links other than http(s), mailto and #fragments;
 * - placeholders in attributes, except plain text in class, title and alt, and a registered web
 *   address placeholder as a link's href. Blocks never go inside a tag.
 *
 * It refuses rather than strips: the administrator sees exactly what is not allowed, and the stored
 * template is what renders. The renderer checks again before every render.
 */
final class HtmlPolicy
{
    private const FORBIDDEN = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'base', 'link', 'meta',
        'form', 'input', 'button', 'select', 'option', 'textarea', 'template', 'portal', 'audio', 'video', 'source', 'track', 'canvas', 'dialog', 'slot', 'noscript', 'title'];
    private const EMBEDDED = ['src', 'srcset', 'background', 'poster', 'data', 'lowsrc', 'dynsrc', 'codebase', 'xlink:href'];
    private const PLACEHOLDER_ATTRIBUTES = ['class', 'title', 'alt', 'href'];

    public function __construct(private readonly CssPolicy $css = new CssPolicy()) {}

    /**
     * Every policy problem, plus the placeholders written inside attribute values so the validator can
     * check them against the registry: href may only hold a web-address placeholder.
     *
     * @return array{errors:list<string>,attribute_placeholders:list<array{element:string,attribute:string,path:string}>}
     */
    public function check(string $html): array
    {
        $errors = [];
        $placeholders = [];
        if (preg_match('/<\s*\/?\s*(html|head|body)\b/i', $html, $match) === 1) {
            $errors[] = 'A template is the body of the document: remove the <' . strtolower($match[1]) . '> tag.';
        }
        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR);
        foreach ($document->querySelectorAll('*') as $element) {
            $tag = strtolower($element->localName);
            if (in_array($tag, ['html', 'head', 'body'], true)) {
                continue;
            }
            if (in_array($tag, self::FORBIDDEN, true) || $tag === 'foreignobject') {
                $errors[] = '<' . $tag . '> elements are not allowed in document templates' . ($tag === 'style' ? '; put styles in the CSS field.' : '.');
                continue;
            }
            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->name);
                $value = (string) $attribute->value;
                $where = '<' . $tag . ' ' . $name . '>';
                if (str_contains($name, '{') || str_contains($name, '}')) {
                    $errors[] = 'Placeholders and blocks cannot stand in for an attribute in <' . $tag . '>; put a placeholder inside an attribute value or between tags.';
                    continue;
                }
                if (str_starts_with($name, 'on')) {
                    $errors[] = 'Event handler attributes such as ' . $where . ' are not allowed.';
                    continue;
                }
                if (preg_match('/\{\{\s*(#|\/|else\b)/', $value) === 1) {
                    $errors[] = 'Blocks such as {{#if}} and {{#each}} cannot go inside an attribute (' . $where . ').';
                    continue;
                }
                if (preg_match('/^\s*(?:javascript|vbscript)\s*:/i', $value) === 1 || preg_match('/(?:javascript|vbscript)\s*:/i', html_entity_decode($value)) === 1) {
                    $errors[] = 'javascript: and vbscript: addresses are not allowed (' . $where . ').';
                    continue;
                }
                if (preg_match_all('/\{\{\s*(' . TemplateParser::PATH . ')\s*\}\}/', $value, $found) > 0) {
                    if (!in_array($name, self::PLACEHOLDER_ATTRIBUTES, true)) {
                        $errors[] = 'Placeholders are only allowed in class, title, alt and a link’s href, not in ' . $where . '.';
                        continue;
                    }
                    foreach ($found[1] as $path) {
                        $placeholders[] = ['element' => $tag, 'attribute' => $name, 'path' => $path];
                    }
                    continue;
                }
                if ($name === 'style') {
                    foreach ($this->css->check($value, 'The style attribute on <' . $tag . '>') as $error) {
                        $errors[] = $error;
                    }
                } elseif (in_array($name, self::EMBEDDED, true) || ($name === 'href' && in_array($tag, ['image', 'use', 'feimage'], true))) {
                    if (!self::isEmbeddedImage($value) && !str_starts_with(trim($value), '#')) {
                        $errors[] = 'Images and other embedded content must be embedded data: images, not ' . self::shorten($value) . ' (' . $where . '). A document must not depend on a file that can change.';
                    }
                } elseif ($name === 'href' && preg_match('#^\s*(https?://|mailto:|\#)#i', $value) !== 1) {
                    $errors[] = 'Links may only go to http(s) or mailto addresses or a #fragment, not ' . self::shorten($value) . ' (' . $where . ').';
                }
            }
        }
        return ['errors' => array_values(array_unique($errors)), 'attribute_placeholders' => $placeholders];
    }

    /** An embedded data: image, or a stored document image the renderer embeds (asset:<sha256>). */
    private static function isEmbeddedImage(string $value): bool
    {
        return preg_match('#^\s*' . CssPolicy::DATA_IMAGE . '#i', $value) === 1 || preg_match('#^' . CssPolicy::ASSET . '$#D', trim($value)) === 1;
    }

    private static function shorten(string $value): string
    {
        $value = trim($value);
        return '"' . (mb_strlen($value) > 40 ? mb_substr($value, 0, 37) . '…' : $value) . '"';
    }
}
