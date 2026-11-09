<?php

declare(strict_types=1);

namespace CattoPHP\Security\TwoStep;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * A QR code to put in a page, drawn on the server, so the key in it never goes to anyone else's service. It is SVG with
 * its own white ground and quiet zone, which a phone reads in a dark theme too, and it carries no inline style, so a
 * strict Content-Security-Policy allows it.
 */
final class QrPicture
{
    private const SIZE = 240;
    private const QUIET_MODULES = 4;

    public static function svg(string $text, string $label = 'QR code for your authenticator app'): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(self::SIZE, self::QUIET_MODULES), new SvgImageBackEnd()));
        $svg = trim((string) preg_replace('/^<\?xml[^>]*\?>/', '', $writer->writeString($text)));

        // Named for a screen reader, which otherwise announces nothing.
        return (string) preg_replace('/^<svg /', '<svg role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '" class="cl-two-step-qr-picture" ', $svg, 1);
    }
}
