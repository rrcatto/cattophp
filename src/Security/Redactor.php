<?php

declare(strict_types=1);

namespace CattoPHP\Security;

/**
 * Hides secrets in anything written down or shown to a person: the activity log's details, a setup step's findings, a
 * check's summary. A value whose name says it is a password, key, token or other secret is kept as ••••, and so are the
 * secrets text can carry by itself: the password in an address (smtp://user:password@host), a secret given by name
 * (password=…, "api_key": "…"), a bearer token and a private key. A whole number under a name that counts things (a
 * plural, such as recovery_codes, or one ending in _count or _left) is how many there are, never a secret, and is kept.
 */
final class Redactor
{
    public const MASK = '••••';

    /** A name that says its value is a secret. */
    public const SECRET_NAMES = '/password|passphrase|secret|token|api_?key|private_?key|signing_?key|^dsn$|_dsn$|cookie|authori[sz]ation|one_time_code|recovery_code/i';

    /** A name that counts things, whose whole number is how many there are: recovery_codes, tokens_left, secret_count. */
    private const COUNT_NAMES = '/(?<!s)s$|_count$|_left$/i';

    /** Each secret text can carry, and what it becomes. */
    private const PATTERNS = [
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s' => self::MASK,
        '#\b([a-z][a-z0-9+.-]*://[^:/@\s]*):[^@/\s]+@#i' => '$1:' . self::MASK . '@',
        '/\b([a-z0-9_-]*(?:password|passwd|passphrase|secret|token|api[_-]?key)["\']?\s*[:=]\s*["\']?)[^\s"\',;&]+/i' => '$1' . self::MASK,
        '/\b(Bearer\s+)[A-Za-z0-9._~+\/=-]+/' => '$1' . self::MASK,
    ];

    /** Text with every secret it carries hidden. */
    public static function text(string $text): string
    {
        foreach (self::PATTERNS as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    /**
     * Values with every secret hidden, at any depth: a secret's name hides its whole value, and other text is cleaned.
     *
     * @param array<array-key,mixed> $values
     * @return array<array-key,mixed>
     */
    public static function data(array $values): array
    {
        $clean = [];
        foreach ($values as $name => $value) {
            // Nothing, and a yes or no (such as secret_changed), cannot give a secret away.
            if (is_string($name) && preg_match(self::SECRET_NAMES, $name) === 1 && $value !== null && $value !== '' && !is_bool($value) && !self::isCount($name, $value)) {
                $clean[$name] = self::MASK;
            } elseif (is_array($value)) {
                $clean[$name] = self::data($value);
            } elseif (is_string($value)) {
                $clean[$name] = self::text($value);
            } else {
                $clean[$name] = $value;
            }
        }

        return $clean;
    }

    /** Whether a value is how many of something there are: a whole number under a name that counts. */
    private static function isCount(string $name, mixed $value): bool
    {
        return is_int($value) && preg_match(self::COUNT_NAMES, $name) === 1;
    }
}
