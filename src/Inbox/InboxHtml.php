<?php

declare(strict_types=1);

namespace CattoPHP\Inbox;

/**
 * The HTML of a reply and its signature: pure. What an editor writes is kept to what it can write (paragraphs, headings,
 * bold, italic, underline, lists, links, quotes and tables), every attribute dropped but a link's web or mail address, so
 * a reply shows on the conversation page and in mail programs as written and nothing else rides along; and a reply's
 * plain text copy, which every HTML reply carries.
 */
final class InboxHtml
{
    private const ALLOWED = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'a', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'figure'];

    public static function clean(string $html): string
    {
        if (trim($html) === '') return '';
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8"?><div id="reply">' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $root = $document->getElementById('reply');
        if ($root === null) return '';
        $clean = static function (\DOMNode $node) use (&$clean): void {
            foreach (iterator_to_array($node->childNodes) as $child) {
                if ($child instanceof \DOMComment) { $node->removeChild($child); continue; }
                if (!$child instanceof \DOMElement) continue;
                $clean($child);
                $tag = strtolower($child->tagName);
                if (!in_array($tag, self::ALLOWED, true)) {
                    if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'template'], true)) { $node->removeChild($child); continue; }
                    while ($child->firstChild !== null) $node->insertBefore($child->firstChild, $child);
                    $node->removeChild($child);
                    continue;
                }
                $href = $tag === 'a' ? trim($child->getAttribute('href')) : '';
                foreach (iterator_to_array($child->attributes) as $attribute) $child->removeAttribute($attribute->nodeName);
                if ($tag === 'a') {
                    if (preg_match('#^(https?://|mailto:)[^\s"<>]+$#i', $href) === 1) $child->setAttribute('href', $href);
                    else { while ($child->firstChild !== null) $node->insertBefore($child->firstChild, $child); $node->removeChild($child); }
                }
            }
        };
        $clean($root);
        $out = '';
        foreach ($root->childNodes as $child) $out .= $document->saveHTML($child);
        return trim($out);
    }

    /** The words of some HTML as plain text: a link as its words and address, a paragraph, heading, row or item on its own line. */
    public static function plain(string $html): string
    {
        $html = (string) preg_replace_callback('#<a\s[^>]*href=(["\'])([^"\']+)\1[^>]*>(.*?)</a>#is', static function (array $m): string {
            $words = trim(strip_tags($m[3]));
            $address = (string) preg_replace('#^mailto:#i', '', $m[2]);
            return $words === '' || $words === $address ? $address : $words . ' (' . $address . ')';
        }, $html);
        $html = (string) preg_replace('#<li\b[^>]*>#i', '- ', $html);
        $html = (string) preg_replace('#<(td|th)\b[^>]*>#i', ' | ', $html);
        $html = (string) preg_replace('#<(br|/p|/div|/h[1-6]|/li|/tr|/blockquote)\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_map(static fn(string $line): string => trim((string) preg_replace('/[ \t]+/', ' ', $line)), explode("\n", $text));
        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /** A reply's HTML as a whole email: the words in a plain, readable frame (a reply is a letter, not a newsletter). */
    public static function document(string $html, string $subject): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</title></head>'
            . '<body style="margin:0;padding:24px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#1f1f1f;background:#ffffff">'
            . '<div style="max-width:640px">' . $html . '</div></body></html>';
    }
}
