<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Security\TwoStep;

use CattoPHP\Foundation\Base32;
use CattoPHP\Security\KeyedHash;
use CattoPHP\Security\TwoStep\OneTimeCode;
use CattoPHP\Security\TwoStep\QrPicture;
use CattoPHP\Security\TwoStep\RecoveryCodes;
use PHPUnit\Framework\TestCase;

final class TwoStepCodesTest extends TestCase
{
    /** RFC 6238's test key, "12345678901234567890". */
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private const KEY = 'cattophp-test-key-not-used-anywhere';

    /** cattolms 1.1.2's hash of recovery code ABCD-2345 for account 1 under KEY: what CattoPHP must go on matching. */
    private const HASHED_BY_CATTOLMS = '05812edd24decebc15763148251e7af6d2b33206a86dec48e06f1fcfab831511';

    public function testBase32RoundTrip(): void
    {
        self::assertSame('12345678901234567890', Base32::decode(self::SECRET));
        self::assertSame(self::SECRET, Base32::encode('12345678901234567890'));
        self::assertSame('MZXW6', Base32::encode('foo'));
        self::assertSame('foo', Base32::decode('mzxw 6==='), 'Small letters, spaces and padding are read.');
        self::assertSame('', Base32::encode(''));
        $bytes = random_bytes(20);
        self::assertSame($bytes, Base32::decode(Base32::encode($bytes)));
    }

    public function testTheCodesOfRfc6238(): void
    {
        // RFC 6238 appendix B, SHA-1: the last 6 digits of its 8.
        foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037', 20000000000 => '353130'] as $time => $code) {
            self::assertSame($code, OneTimeCode::codeAt(self::SECRET, $time), (string) $time);
        }
    }

    public function testTheAddressAnAppReadsFromTheQrCode(): void
    {
        self::assertSame(
            'otpauth://totp/Garden%20School:someone%40example.com?secret=' . self::SECRET . '&issuer=Garden%20School&algorithm=SHA1&digits=6&period=30',
            OneTimeCode::appAddress(self::SECRET, 'Garden School', 'someone@example.com'),
        );
        self::assertSame('GEZD GNBV GY3T QOJQ GEZD GNBV GY3T QOJQ', OneTimeCode::grouped(self::SECRET), 'The key to type is in groups of four.');
        self::assertSame(32, strlen(OneTimeCode::newSecret()), 'A new key is 20 random bytes.');
    }

    public function testTheQrCodeIsAPictureInThePageAndNothingElse(): void
    {
        $svg = QrPicture::svg(OneTimeCode::appAddress(self::SECRET, 'Garden School', 'someone@example.com'));
        self::assertStringStartsWith('<svg role="img" aria-label="QR code for your authenticator app"', $svg);
        self::assertStringEndsWith('</svg>', $svg);
        self::assertDoesNotMatchRegularExpression('/<script|style=|href=|<\?xml/i', $svg, 'A strict Content-Security-Policy allows no inline styles.');
        self::assertStringNotContainsString(self::SECRET, $svg, 'The key is in the pattern, not in the page source.');
        self::assertStringContainsString('aria-label="Code &amp; key"', QrPicture::svg('x', 'Code & key'), 'A label is escaped.');
    }

    public function testAPhoneHalfAMinuteOutIsAcceptedAndNoMore(): void
    {
        self::assertNotNull(OneTimeCode::matchingCounter(self::SECRET, OneTimeCode::codeAt(self::SECRET, 1000000), 1000030));
        self::assertNotNull(OneTimeCode::matchingCounter(self::SECRET, OneTimeCode::codeAt(self::SECRET, 1000030), 1000000));
        self::assertNull(OneTimeCode::matchingCounter(self::SECRET, OneTimeCode::codeAt(self::SECRET, 1000000), 1000300));
        self::assertSame(intdiv(1000000, 30), OneTimeCode::matchingCounter(self::SECRET, ' ' . OneTimeCode::codeAt(self::SECRET, 1000000) . ' ', 1000000), 'Spaces around it are allowed.');
    }

    public function testACodeUsedOnceCannotBeUsedAgain(): void
    {
        $code = OneTimeCode::codeAt(self::SECRET, 1000000);
        $step = OneTimeCode::matchingCounter(self::SECRET, $code, 1000000);
        self::assertNotNull($step);
        self::assertNull(OneTimeCode::matchingCounter(self::SECRET, $code, 1000000, $step), 'Its step is the last one used.');
        self::assertNull(OneTimeCode::matchingCounter(self::SECRET, OneTimeCode::codeAt(self::SECRET, 1000000 - 30), 1000000, $step), 'Nor one before it.');
        self::assertNotNull(OneTimeCode::matchingCounter(self::SECRET, OneTimeCode::codeAt(self::SECRET, 1000030), 1000030, $step), 'The next works.');
    }

    public function testRubbishIsNotACode(): void
    {
        foreach (['', '12345', '1234567', 'abcdef', '12-345'] as $code) {
            self::assertNull(OneTimeCode::matchingCounter(self::SECRET, $code, 1000000), $code);
        }
    }

    public function testEightRecoveryCodesInTheirOwnForm(): void
    {
        $codes = RecoveryCodes::make();
        self::assertCount(8, $codes);
        self::assertCount(8, array_unique($codes));
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression(RecoveryCodes::SHAPE, $code);
            self::assertDoesNotMatchRegularExpression('/[01IO]/', $code, 'Nothing that reads as something else.');
        }
        self::assertSame('ABCD-2345', RecoveryCodes::normalise(' abcd 2345 '));
        self::assertSame('ABCD-2345', RecoveryCodes::normalise('ABCD-2345'));
        foreach (['', 'ABCD-234', 'ABCD-23450', 'OBCD-2345', 'ABCD-1234'] as $wrong) {
            self::assertNull(RecoveryCodes::normalise($wrong), $wrong);
        }
    }

    public function testARecoveryCodeIsKeptOnlyAsAKeyedHashTiedToItsAccount(): void
    {
        $hash = new KeyedHash(self::KEY);
        self::assertSame(self::HASHED_BY_CATTOLMS, RecoveryCodes::hash($hash, 1, 'ABCD-2345'), 'The codes cattolms has kept go on working.');
        self::assertNotSame(RecoveryCodes::hash($hash, 1, 'ABCD-2345'), RecoveryCodes::hash($hash, 2, 'ABCD-2345'), 'A code is tied to its account.');
        self::assertSame(RecoveryCodes::hash($hash, 1, 'ABCD-2345'), RecoveryCodes::hash($hash, '1', 'ABCD-2345'), 'An account named by a number or a reference.');
        self::assertNotSame(hash('sha256', '1:ABCD-2345'), RecoveryCodes::hash($hash, 1, 'ABCD-2345'), 'The hash is keyed.');
    }
}
