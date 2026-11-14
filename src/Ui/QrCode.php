<?php

declare(strict_types=1);

namespace CattoPHP\Ui;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use InvalidArgumentException;

/**
 * A QR code of any text (an address, a key), drawn on the server, so what it holds never goes to anyone else's service.
 * It is SVG with its own white ground and quiet zone, which a phone reads in a dark theme too, and it carries no inline
 * style, so a strict Content-Security-Policy allows it. svg() is for a page: a picture named for a screen reader, with a
 * class for the page's stylesheet. document() is a file of its own, to download for a printed advert or a slide.
 */
final class QrCode
{
    /** The width and height in a page, in pixels. */
    public const SIZE = 240;

    /** The width and height of a file of its own: large enough to print sharp, though SVG scales anyway. */
    public const FILE_SIZE = 1024;

    /** The white margin round it, in modules: four, as a reader expects. */
    private const QUIET_MODULES = 4;

    private const CLASS_NAME = '/^[a-z][a-z0-9-]*$/';

    private function __construct()
    {
    }

    /**
     * The QR code for a page: an `<svg>` element named by $label for a screen reader (role img) with the class given.
     */
    public static function svg(string $text, string $label, string $class = 'cl-qr-code', int $size = self::SIZE): string
    {
        if (preg_match(self::CLASS_NAME, $class) !== 1) {
            throw new InvalidArgumentException('A QR code\'s class is lower-case letters, digits and hyphens: "' . $class . '" is not.');
        }
        $svg = trim((string) preg_replace('/^<\?xml[^>]*\?>/', '', self::draw($text, $size)));

        // Named for a screen reader, which otherwise announces nothing.
        return (string) preg_replace('/^<svg /', '<svg role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '" class="' . $class . '" ', $svg, 1);
    }

    /**
     * The QR code as a file of its own: the XML declaration, then the `<svg>` with a title saying what it holds.
     */
    public static function document(string $text, string $title = '', int $size = self::FILE_SIZE): string
    {
        $svg = trim((string) preg_replace('/^<\?xml[^>]*\?>/', '', self::draw($text, $size)));
        if ($title !== '') {
            $svg = (string) preg_replace('/^(<svg[^>]*>)/', '$1<title>' . htmlspecialchars($title, ENT_QUOTES | ENT_XML1) . '</title>', $svg, 1);
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $svg . "\n";
    }

    private static function draw(string $text, int $size): string
    {
        if ($text === '') {
            throw new InvalidArgumentException('A QR code needs something to hold.');
        }
        if ($size < 64 || $size > 4096) {
            throw new InvalidArgumentException('A QR code is 64 to 4,096 pixels across.');
        }

        return (new Writer(new ImageRenderer(new RendererStyle($size, self::QUIET_MODULES), new SvgImageBackEnd())))->writeString($text);
    }
}
