<?php

declare(strict_types=1);

namespace CattoPHP\Identity;

use JsonException;
use stdClass;

/**
 * Checks that a webhook came from Didit (https://docs.didit.me/integration/webhooks). Didit signs every webhook with
 * HMAC-SHA256 and the destination's secret. X-Signature-V2, the one Didit recommends, signs the body as canonical JSON:
 * keys sorted, compact separators, Unicode not escaped, and whole-valued numbers written as integers. X-Signature signs
 * the exact bytes sent and is the fallback. X-Signature-Simple signs only the envelope, not the decision, and is not
 * accepted. A webhook older or newer than 5 minutes (X-Timestamp) is refused, so an old one cannot be replayed.
 *
 * For 24 hours after a destination's secret is rotated in Didit, each webhook also carries X-Signature-V2-Previous and
 * X-Signature-Previous, made with the replaced secret: either is accepted, so a site still holding the old secret keeps
 * working until its secret is updated.
 */
final class DiditSignature
{
    /** How far a webhook's timestamp may be from now, in seconds. */
    public const TOLERANCE = 300;

    public static function verify(string $rawBody, string $signatureV2, string $signature, string $timestamp, string $secret, int $now, string $signatureV2Previous = '', string $signaturePrevious = ''): bool
    {
        if ($secret === '' || preg_match('/^\d{1,12}$/', $timestamp) !== 1 || abs($now - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }
        $canonical = self::canonical($rawBody);
        if ($canonical !== null) {
            $expected = hash_hmac('sha256', $canonical, $secret);
            foreach ([$signatureV2, $signatureV2Previous] as $sent) {
                if ($sent !== '' && hash_equals($expected, strtolower(trim($sent)))) return true;
            }
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        foreach ([$signature, $signaturePrevious] as $sent) {
            if ($sent !== '' && hash_equals($expected, strtolower(trim($sent)))) return true;
        }
        return false;
    }

    /** The body as Didit signs it for X-Signature-V2, or null when it is not JSON. */
    public static function canonical(string $rawBody): ?string
    {
        try {
            $decoded = json_decode($rawBody, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            return json_encode(self::normalise($decoded), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
        } catch (JsonException) {
            return null;
        }
    }

    /** Keys sorted at every level and whole-valued floats made integers, as Didit does before signing. */
    private static function normalise(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $fields = get_object_vars($value);
            ksort($fields, SORT_STRING);
            $sorted = new stdClass();
            foreach ($fields as $key => $field) {
                $sorted->{(string) $key} = self::normalise($field);
            }
            return $sorted;
        }
        if (is_array($value)) {
            return array_map(self::normalise(...), $value);
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 1e15) {
            return (int) $value;
        }
        return $value;
    }
}
