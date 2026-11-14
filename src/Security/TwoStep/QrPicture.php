<?php

declare(strict_types=1);

namespace CattoPHP\Security\TwoStep;

use CattoPHP\Ui\QrCode;

/**
 * The QR code an authenticator app reads to set up two-step sign-in, drawn on the server by Ui\QrCode, so the key in it
 * never goes to anyone else's service: SVG with its own white ground, no inline style, named for a screen reader.
 */
final class QrPicture
{
    public static function svg(string $text, string $label = 'QR code for your authenticator app'): string
    {
        return QrCode::svg($text, $label, 'cl-two-step-qr-picture');
    }
}
