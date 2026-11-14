<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Ui;

use CattoPHP\Security\TwoStep\QrPicture;
use CattoPHP\Ui\QrCode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** A QR code of any text: a picture in a page named for a screen reader, or a file of its own, never an inline style. */
final class QrCodeTest extends TestCase
{
    public function testAPageGetsAPictureNamedForAScreenReaderWithItsClass(): void
    {
        $svg = QrCode::svg('https://example.com/c/spring-sale/fb1', 'QR code of the tracking link');
        self::assertStringStartsWith('<svg role="img" aria-label="QR code of the tracking link" class="cl-qr-code" xmlns="http://www.w3.org/2000/svg"', $svg);
        self::assertStringEndsWith('</svg>', $svg);
        self::assertDoesNotMatchRegularExpression('/<script|style=|href=|<\?xml/i', $svg);
        self::assertStringNotContainsString('example.com', $svg, 'What it holds is in the pattern, not in the page.');
        self::assertStringContainsString('width="240" height="240"', $svg);
        self::assertStringContainsString('aria-label="Fish &amp; chips"', QrCode::svg('x', 'Fish & chips', 'cl-other'));
    }

    public function testTwoStepSignInGetsTheSamePictureAsBefore(): void
    {
        $text = 'otpauth://totp/Example:someone%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=Example';
        self::assertSame(str_replace('class="cl-qr-code"', 'class="cl-two-step-qr-picture"', QrCode::svg($text, 'QR code for your authenticator app')), QrPicture::svg($text));
        self::assertStringStartsWith('<svg role="img" aria-label="QR code for your authenticator app" class="cl-two-step-qr-picture" ', QrPicture::svg($text));
    }

    public function testAFileOfItsOwnHasTheXmlDeclarationAndATitle(): void
    {
        $file = QrCode::document('https://example.com/c/spring-sale/fb1', 'QR code of https://example.com/c/spring-sale/fb1 & more');
        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<svg xmlns="http://www.w3.org/2000/svg"', $file);
        self::assertStringContainsString('width="1024" height="1024"', $file);
        self::assertStringContainsString('<title>QR code of https://example.com/c/spring-sale/fb1 &amp; more</title>', $file);
        self::assertSame(1, substr_count($file, '<?xml'));
        self::assertNotFalse(simplexml_load_string($file), 'A well-formed SVG file.');
    }

    public function testNothingToHoldABadClassOrSizeIsRefused(): void
    {
        foreach ([static fn () => QrCode::svg('', 'Empty'), static fn () => QrCode::svg('x', 'Bad', 'bad class'), static fn () => QrCode::document('x', '', 10)] as $refused) {
            try {
                $refused();
                self::fail('Refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
