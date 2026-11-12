<?php

declare(strict_types=1);

namespace CattoPHP\Inbox;

/**
 * An email as the Inbox reads an answer from the mailbox: pure. From the raw message it reads who sent it, its subject,
 * its Message-ID and the ones it answers (In-Reply-To and References), its date, its words (the plain text part, else the
 * HTML part as text) and the names of its attachments, decoding headers (RFC 2047), transfer encodings (base64,
 * quoted-printable) and character sets into UTF-8. An answer's attachments are named in the conversation, not kept.
 */
final class MimeMessage
{
    /**
     * @param list<string> $answers the Message-IDs it answers, newest first, without angle brackets
     * @param list<string> $attachments
     */
    private function __construct(
        public readonly string $fromName,
        public readonly string $fromEmail,
        public readonly string $subject,
        public readonly string $messageId,
        public readonly array $answers,
        public readonly ?string $date,
        public readonly string $text,
        public readonly array $attachments,
        public readonly bool $automatic,
    ) {}

    public static function parse(string $raw): self
    {
        [$headers, $body] = self::split($raw);
        $from = self::address(self::header($headers, 'from'));
        $answers = [];
        foreach ([self::header($headers, 'in-reply-to'), self::header($headers, 'references')] as $value) {
            if (preg_match_all('/<([^<>\s]+)>/', $value, $ids) > 0) foreach (array_reverse($ids[1]) as $id) $answers[] = $id;
        }
        $parts = ['text' => null, 'html' => null, 'attachments' => []];
        self::walk($headers, $body, $parts, 0);
        $text = $parts['text'] ?? ($parts['html'] !== null ? InboxHtml::plain($parts['html']) : '');
        $date = self::header($headers, 'date');
        $time = $date !== '' ? strtotime($date) : false;
        $auto = strtolower(self::header($headers, 'auto-submitted'));
        return new self(
            $from['name'], $from['email'], self::decode(self::header($headers, 'subject')),
            trim(self::header($headers, 'message-id'), " \t<>"), array_values(array_unique($answers)),
            $time !== false ? date(DATE_ATOM, $time) : null,
            trim(str_replace("\r\n", "\n", $text)), $parts['attachments'],
            ($auto !== '' && $auto !== 'no') || self::header($headers, 'x-autoreply') !== '' || stripos(self::header($headers, 'precedence'), 'auto') !== false,
        );
    }

    /** @return array{0:array<string,list<string>>,1:string} the headers by lower-case name, and the body */
    private static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $at = strpos($raw, "\n\n");
        $head = $at === false ? $raw : substr($raw, 0, $at);
        $body = $at === false ? '' : substr($raw, $at + 2);
        $head = (string) preg_replace("/\n[ \t]+/", ' ', $head);
        $headers = [];
        foreach (explode("\n", $head) as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) continue;
            $headers[strtolower(trim(substr($line, 0, $colon)))][] = trim(substr($line, $colon + 1));
        }
        return [$headers, $body];
    }

    /** @param array<string,list<string>> $headers */
    private static function header(array $headers, string $name): string
    {
        return $headers[$name][0] ?? '';
    }

    /**
     * Walks a part and its parts, keeping the first plain text and HTML bodies and every attachment's name.
     *
     * @param array<string,list<string>> $headers
     * @param array{text:?string,html:?string,attachments:list<string>} $parts
     */
    private static function walk(array $headers, string $body, array &$parts, int $depth): void
    {
        if ($depth > 8) return;
        $type = self::header($headers, 'content-type') ?: 'text/plain';
        $mime = strtolower(trim((string) strtok($type, ';')));
        $disposition = self::header($headers, 'content-disposition');
        $name = self::parameter($disposition, 'filename') ?? self::parameter($type, 'name');
        if (str_starts_with($mime, 'multipart/')) {
            $boundary = self::parameter($type, 'boundary');
            if ($boundary === null || $boundary === '') return;
            $sections = explode("\n--" . $boundary, "\n" . $body);
            array_shift($sections);
            foreach ($sections as $section) {
                if (str_starts_with($section, '--')) break;
                [$partHeaders, $partBody] = self::split(ltrim($section, " \t") . (str_contains($section, "\n\n") ? '' : "\n\n"));
                self::walk($partHeaders, preg_replace('/^\n/', '', $partBody) ?? $partBody, $parts, $depth + 1);
            }
            return;
        }
        if ($name !== null || stripos($disposition, 'attachment') === 0) {
            $parts['attachments'][] = $name !== null && $name !== '' ? self::decode($name) : 'attachment';
            return;
        }
        if ($mime === 'message/rfc822') return;
        $content = self::transfer($body, strtolower(self::header($headers, 'content-transfer-encoding')));
        $charset = self::parameter($type, 'charset') ?? 'UTF-8';
        $content = self::utf8($content, $charset);
        if ($mime === 'text/plain' && $parts['text'] === null) $parts['text'] = $content;
        elseif ($mime === 'text/html' && $parts['html'] === null) $parts['html'] = $content;
    }

    private static function parameter(string $header, string $name): ?string
    {
        if (preg_match('/(?:^|;)\s*' . preg_quote($name, '/') . '\*?\s*=\s*("([^"]*)"|[^;\s]+)/i', $header, $match) !== 1) return null;
        // Quoted, the words between the quotes (perhaps none); bare, the word itself.
        $value = str_starts_with($match[1], '"') ? ($match[2] ?? '') : $match[1];
        // RFC 2231: charset''percent-encoded
        if (preg_match("/^([A-Za-z0-9_-]+)''(.*)$/", $value, $encoded) === 1) return self::utf8(rawurldecode($encoded[2]), $encoded[1]);
        return $value;
    }

    private static function transfer(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode((string) preg_replace('/\s+/', '', $body), false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    private static function utf8(string $text, string $charset): string
    {
        $charset = strtoupper(trim($charset, " \"'"));
        if ($charset === '' || $charset === 'UTF-8' || $charset === 'US-ASCII') return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
        return is_string($converted) ? $converted : mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
    }

    /** A header's encoded words (=?UTF-8?B?...?=) in UTF-8. */
    private static function decode(string $value): string
    {
        if (!str_contains($value, '=?')) return $value;
        $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        return $decoded !== false ? $decoded : $value;
    }

    /** @return array{name:string,email:string} */
    private static function address(string $value): array
    {
        $value = self::decode($value);
        if (preg_match('/^(.*)<([^<>@\s]+@[^<>\s]+)>\s*$/', $value, $match) === 1) return ['name' => trim($match[1], " \t\""), 'email' => mb_strtolower($match[2])];
        return ['name' => '', 'email' => mb_strtolower(trim($value, " \t<>"))];
    }
}
