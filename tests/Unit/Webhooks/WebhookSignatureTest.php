<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Webhooks;

use CattoPHP\Webhooks\WebhookSignature;
use PHPUnit\Framework\TestCase;

/** A signed webhook: made by the sender, checked by the receiver, ported from cattolms's catto-mail webhook test. */
final class WebhookSignatureTest extends TestCase
{
    public function testASignatureIsCheckedWithinItsToleranceAndASecretCanBeRotated(): void
    {
        $now = 1794470400;
        $body = '{"event":"message.delivered","reference":"abc"}';
        $header = WebhookSignature::sign($body, ['old-secret'], $now);
        self::assertSame('t=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $body, 'old-secret'), $header);
        self::assertTrue(WebhookSignature::valid($header, $body, ['new-secret', 'old-secret'], $now + 10), 'The previous secret still checks while rotating.');
        self::assertFalse(WebhookSignature::valid($header, $body, ['new-secret'], $now));
        self::assertFalse(WebhookSignature::valid($header, $body . ' ', ['old-secret'], $now), 'A changed body fails.');
        self::assertFalse(WebhookSignature::valid($header, $body, ['old-secret'], $now + 301), 'A stale one fails.');
        self::assertFalse(WebhookSignature::valid($header, $body, ['old-secret'], $now - 301), 'So does one from the future.');
        self::assertFalse(WebhookSignature::valid('v1=abc', $body, ['old-secret'], $now));
        self::assertFalse(WebhookSignature::valid($header, $body, [''], $now), 'An empty secret checks nothing.');

        $both = WebhookSignature::sign($body, ['new-secret', 'old-secret', ''], $now);
        self::assertSame(2, substr_count($both, 'v1='), 'Signed with both while rotating, never with an empty secret.');
        self::assertTrue(WebhookSignature::valid($both, $body, ['new-secret'], $now));
        self::assertTrue(WebhookSignature::valid($both, $body, ['old-secret'], $now));
        self::assertTrue(WebhookSignature::valid(' t=' . $now . ' , v1=' . hash_hmac('sha256', $now . '.' . $body, 'old-secret'), $body, ['old-secret'], $now), 'Spaces around the parts are read.');
    }
}
