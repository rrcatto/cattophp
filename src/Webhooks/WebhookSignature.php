<?php

declare(strict_types=1);

namespace CattoPHP\Webhooks;

/**
 * A signed webhook's signature header, as the sender makes it and the receiver checks it: "t=<unix time>,v1=<hex>",
 * where v1 is the HMAC-SHA256 of "<t>.<body>" with the endpoint's shared secret. The receiver refuses one more than
 * TOLERANCE seconds from its own time (an old message replayed), and accepts any of several secrets and several v1
 * values, so a secret can be rotated without a message being lost: the sender signs with both while the receiver
 * learns the new one.
 */
final class WebhookSignature
{
    public const TOLERANCE = 300;

    /**
     * The header for a body sent at a moment, signed with each secret given (one, or two while rotating).
     *
     * @param list<string> $secrets
     */
    public static function sign(string $body, array $secrets, int $time): string
    {
        $parts = ['t=' . $time];
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $parts[] = 'v1=' . hash_hmac('sha256', $time . '.' . $body, $secret);
            }
        }

        return implode(',', $parts);
    }

    /**
     * Whether a body's header is signed by one of the secrets, within TOLERANCE seconds of now.
     *
     * @param list<string> $secrets
     */
    public static function valid(string $header, string $body, array $secrets, int $now, int $tolerance = self::TOLERANCE): bool
    {
        $time = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && preg_match('/^\d+$/', $value) === 1) {
                $time = (int) $value;
            }
            if ($key === 'v1' && preg_match('/^[0-9a-f]{64}$/', $value) === 1) {
                $signatures[] = $value;
            }
        }
        if ($time === null || $signatures === [] || abs($now - $time) > $tolerance) {
            return false;
        }
        foreach (array_filter($secrets, static fn (string $secret): bool => $secret !== '') as $secret) {
            $expected = hash_hmac('sha256', $time . '.' . $body, $secret);
            foreach ($signatures as $signature) {
                if (hash_equals($expected, $signature)) {
                    return true;
                }
            }
        }

        return false;
    }
}
