<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use Doctrine\DBAL\Connection;

/**
 * Where the Campaign Marketing Manager's uploaded pictures are kept, for adverts and landing pages alike: under the module's
 * files folder (marketing/pictures/), each named by its content's SHA-256 and its kind, so a copied advert or page shares
 * its picture. A picture is removed only when no advert, landing page, section, kept version or saved template names it.
 * A picture is taken only as a JPEG, PNG or WebP, judged by what the file holds, never by its name, and only up to the size
 * the settings allow. The folder is never served by the web server: a page reads a picture back for someone allowed to
 * see it. Pictures kept before in an earlier folder (marketing/adverts/) are still read and removed there.
 */
final class Pictures
{
    /** Each kind taken, by the extension its stored name ends with, and its type. */
    public const KINDS = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public const NAME = '/^[0-9a-f]{64}\.(jpg|png|webp)$/';

    /** What may name a picture, each as a query that finds the name given as :name or as :quoted (the name in quotes, inside JSON). */
    private const NAMED_BY = [
        'SELECT 1 FROM cattophp_marketing_adverts WHERE picture = :name',
        'SELECT 1 FROM cattophp_marketing_landing_sections WHERE strpos(data::text, :quoted) > 0',
        'SELECT 1 FROM cattophp_marketing_landing_pages WHERE strpos(COALESCE(share_picture::text, \'\'), :quoted) > 0',
        'SELECT 1 FROM cattophp_marketing_landing_versions WHERE strpos(COALESCE(content::text, \'\'), :quoted) > 0',
        'SELECT 1 FROM cattophp_marketing_landing_templates WHERE strpos(sections::text, :quoted) > 0',
    ];

    /** @param list<string> $earlier folders pictures were kept in before, read and tidied as well */
    public function __construct(private readonly Connection $connection, private readonly string $folder, private readonly array $earlier = [])
    {
    }

    /**
     * Keeps a picture from a file (an upload's temporary file), and gives its stored name.
     *
     * @throws MarketingRefused when it is not a JPEG, PNG or WebP picture, or is larger than allowed
     */
    public function store(string $path, int $largest, string $field = 'picture'): string
    {
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $size === 0) {
            throw new MarketingRefused([$field => 'The picture did not arrive: choose it again.']);
        }
        if ($size > $largest) {
            throw new MarketingRefused([$field => sprintf('The picture is %s; the largest allowed is %s.', self::size($size), self::size($largest))]);
        }
        $content = (string) file_get_contents($path);
        $kind = self::kindOf($content);
        if ($kind === null) {
            throw new MarketingRefused([$field => 'Choose a JPEG, PNG or WebP picture.']);
        }
        $name = hash('sha256', $content) . '.' . $kind;
        $target = $this->folder . '/' . $name;
        if (!is_file($target)) {
            if (!is_dir($this->folder) && !mkdir($this->folder, 0770, true) && !is_dir($this->folder)) {
                throw new \RuntimeException('The folder for pictures could not be made: ' . $this->folder);
            }
            $temporary = $target . '.' . bin2hex(random_bytes(4)) . '.part';
            if (file_put_contents($temporary, $content) !== strlen($content) || !rename($temporary, $target)) {
                @unlink($temporary);
                throw new \RuntimeException('A picture could not be kept in ' . $this->folder);
            }
            chmod($target, 0640);
        }

        return $name;
    }

    /** A stored picture read back, or null when the name is not one or its file is gone. */
    public function read(?string $name): ?Picture
    {
        if ($name === null || preg_match(self::NAME, $name, $match) !== 1) {
            return null;
        }
        foreach ([$this->folder, ...$this->earlier] as $folder) {
            $path = $folder . '/' . $name;
            $size = is_file($path) ? filesize($path) : false;
            if ($size !== false) {
                return new Picture($name, $path, self::KINDS[$match[1]], $size);
            }
        }

        return null;
    }

    /** Whether anything still names a stored picture: an advert, a landing page's section or share picture, a kept version or a saved template. */
    public function inUse(string $name): bool
    {
        $quoted = '"' . $name . '"';
        foreach (self::NAMED_BY as $query) {
            if ($this->connection->fetchOne($query . ' LIMIT 1', str_contains($query, ':quoted') ? ['quoted' => $quoted] : ['name' => $name]) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Removes a stored picture when nothing names it any more. */
    public function removeUnused(string $name): void
    {
        if (preg_match(self::NAME, $name) !== 1 || $this->inUse($name)) {
            return;
        }
        foreach ([$this->folder, ...$this->earlier] as $folder) {
            @unlink($folder . '/' . $name);
        }
    }

    /** The kind of picture some bytes are (jpg, png or webp) by what they hold and whether they read as a picture, or null. */
    public static function kindOf(string $content): ?string
    {
        $kind = match (true) {
            str_starts_with($content, "\xFF\xD8\xFF") && str_contains(substr($content, -64), "\xFF\xD9") => 'jpg',
            str_starts_with($content, "\x89PNG\r\n\x1A\n") && substr($content, 12, 4) === 'IHDR' && str_contains(substr($content, -32), 'IEND') => 'png',
            strlen($content) >= 16 && str_starts_with($content, 'RIFF') && substr($content, 8, 4) === 'WEBP' && in_array(substr($content, 12, 4), ['VP8 ', 'VP8L', 'VP8X'], true) => 'webp',
            default => null,
        };
        if ($kind === null) {
            return null;
        }
        $image = @getimagesizefromstring($content);
        if ($image === false || $image[0] < 1 || $image[1] < 1 || $image['mime'] !== self::KINDS[$kind]) {
            return null;
        }
        // Where PHP can draw pictures, the picture is drawn too, so only one that opens is kept.
        if (function_exists('imagecreatefromstring') && @imagecreatefromstring($content) === false) {
            return null;
        }

        return $kind;
    }

    /** A number of bytes in words: 820 KB, 4.5 MB. */
    public static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
