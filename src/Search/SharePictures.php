<?php

declare(strict_types=1);

namespace CattoPHP\Search;

use CattoPHP\Foundation\SvgRaster;
use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Share pictures: what Facebook, LinkedIn, X, WhatsApp and Google show when a page is shared or listed. A cover drawn as
 * SVG, which none of them accepts, gets a PNG copy at 1200 by 630, the size Open Graph and X's large card ask for, drawn
 * by rsvg-convert (Foundation\SvgRaster). Without it nothing is drawn and the pages carry no og:image.
 *
 * The cover's own canvas is drawn into 1200 by 630 by giving the root svg that size and preserveAspectRatio "xMidYMid
 * slice", so the picture is cropped a little top and bottom exactly as a card crops it, never stretched. It is read from
 * standard input, so it can load no file, and it may take no longer than TIMEOUT seconds.
 *
 * Made when a cover is saved, never on a request. Each picture is a file in the folder given, named by the SHA-256 of the
 * cover and the drawing's version, so the same cover is drawn once, a changed cover is a new file and a new ?v= version
 * of its address, and a request only asks whether the file is there.
 */
final class SharePictures
{
    public const WIDTH = 1200;
    public const HEIGHT = 630;
    /** Raised when the drawing changes, so every picture is drawn again. */
    private const DRAWING = 'share-1200x630-v1';
    private const TIMEOUT = 20;
    /** The largest cover drawn: a cover is a few kilobytes, and the course form takes nothing larger. */
    private const MOST_BYTES = 2000000;

    public function __construct(private readonly string $folder, private readonly ?string $tool = null)
    {
    }

    /** The full path of rsvg-convert, or null when this server has none. */
    public function tool(): ?string
    {
        return SvgRaster::tool($this->tool);
    }

    public function available(): bool
    {
        return $this->tool() !== null;
    }

    /** The picture's version: the start of its file name, for the ?v= of its address. Null for no usable cover. */
    public static function version(string $svg): ?string
    {
        return self::usable($svg) ? substr(self::hash($svg), 0, 16) : null;
    }

    /** Whether the PNG of this cover has been drawn. */
    public function has(string $svg): bool
    {
        return self::usable($svg) && is_file($this->path($svg));
    }

    /** The stored PNG's bytes, or null when it has not been drawn. */
    public function bytes(string $svg): ?string
    {
        if (!$this->has($svg)) return null;
        $bytes = file_get_contents($this->path($svg));
        return $bytes === false || $bytes === '' ? null : $bytes;
    }

    /**
     * Draws the cover's PNG unless it is there already. Returns whether it is there now; false when there is no tool, the
     * cover is not SVG, or drawing failed (never thrown: saving a course never fails because of its share picture).
     */
    public function make(string $svg): bool
    {
        if (!self::usable($svg)) return false;
        if ($this->has($svg)) return true;
        $tool = $this->tool();
        if ($tool === null) return false;
        try {
            $png = $this->draw($tool, self::sized($svg));
        } catch (RuntimeException) {
            return false;
        }
        $folder = rtrim($this->folder, '/');
        if (!is_dir($folder) && !@mkdir($folder, 0775, true) && !is_dir($folder)) return false;
        $target = $this->path($svg);
        $partial = $target . '.' . bin2hex(random_bytes(4)) . '.part';
        if (@file_put_contents($partial, $png) !== strlen($png)) { @unlink($partial); return false; }
        return @rename($partial, $target);
    }

    /** Whether a cover is SVG markup this can draw. */
    public static function usable(string $svg): bool
    {
        $svg = trim($svg);
        return $svg !== '' && strlen($svg) <= self::MOST_BYTES && preg_match('/^<svg\b/i', $svg) === 1;
    }

    private function path(string $svg): string
    {
        return rtrim($this->folder, '/') . '/' . self::hash($svg) . '.png';
    }

    private static function hash(string $svg): string
    {
        return hash('sha256', self::DRAWING . "\n" . trim($svg));
    }

    /**
     * The cover with its root at 1200 by 630 and its canvas sliced into it, as a card shows it. A cover without a viewBox
     * gets one from its own width and height, or the specification's 640 by 360.
     */
    public static function sized(string $svg): string
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // No network and no entity expansion: a cover can load nothing.
        $loaded = $document->loadXML(trim($svg), LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $loaded ? $document->documentElement : null;
        if (!$root instanceof DOMElement || strtolower((string) $root->localName) !== 'svg') throw new RuntimeException('The cover is not SVG.');
        if (!$root->hasAttribute('viewBox')) {
            $width = (float) preg_replace('/[^0-9.]/', '', $root->getAttribute('width'));
            $height = (float) preg_replace('/[^0-9.]/', '', $root->getAttribute('height'));
            $root->setAttribute('viewBox', $width > 0 && $height > 0 ? '0 0 ' . $width . ' ' . $height : '0 0 640 360');
        }
        $root->setAttribute('width', (string) self::WIDTH);
        $root->setAttribute('height', (string) self::HEIGHT);
        $root->setAttribute('preserveAspectRatio', 'xMidYMid slice');
        $sized = $document->saveXML($root);
        if ($sized === false) throw new RuntimeException('The cover could not be read.');
        return $sized;
    }

    private function draw(string $tool, string $svg): string
    {
        $png = SvgRaster::png($tool, $svg, ['--background-color=white'], self::TIMEOUT);
        $size = $png === '' ? false : @getimagesizefromstring($png);
        if ($size === false || $size[0] !== self::WIDTH || $size[1] !== self::HEIGHT || $size['mime'] !== 'image/png') throw new RuntimeException('rsvg-convert drew nothing usable.');
        return $png;
    }
}
