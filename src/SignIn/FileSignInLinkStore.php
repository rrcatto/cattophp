<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

use DateTimeImmutable;
use RuntimeException;

/**
 * Sign-in links in files only the site's own account can read, for an installation without a database: one file per
 * link, named by its hash, so a copy of the folder signs nobody in. A link is used up by renaming its file, which only
 * one of two attempts at the same moment can do.
 */
final class FileSignInLinkStore implements SignInLinkStore
{
    public function __construct(private readonly string $folder)
    {
    }

    public function add(string $hash, SignInLink $link, DateTimeImmutable $at): void
    {
        $path = $this->path($hash);
        $value = json_encode(['account' => $link->accountId, 'expires' => $link->expiresAt->getTimestamp(), 'return' => $link->returnPath], JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $value) === false) {
            throw new RuntimeException('The sign-in link could not be kept.');
        }
        chmod($path, 0600);
    }

    public function usable(string $hash, DateTimeImmutable $now): ?SignInLink
    {
        $text = @file_get_contents($this->path($hash));
        $link = is_string($text) ? self::read($text) : null;

        return $link !== null && $link->expiresAt > $now ? $link : null;
    }

    public function claim(string $hash, DateTimeImmutable $now): ?SignInLink
    {
        $link = $this->usable($hash, $now);
        if ($link === null) {
            return null;
        }
        $path = $this->path($hash);
        $used = $path . '.used';
        if (!@rename($path, $used)) {
            return null;
        }
        @unlink($used);

        return $link;
    }

    public function forgetOld(DateTimeImmutable $now): void
    {
        foreach (glob($this->folder() . '/*') ?: [] as $file) {
            $text = @file_get_contents($file);
            $link = is_string($text) ? self::read($text) : null;
            if ($link === null || $link->expiresAt <= $now || str_ends_with($file, '.used')) {
                @unlink($file);
            }
        }
    }

    private static function read(string $text): ?SignInLink
    {
        $value = json_decode($text, true);
        if (!is_array($value) || !is_string($value['account'] ?? null) || !is_int($value['expires'] ?? null)) {
            return null;
        }

        return new SignInLink($value['account'], (new DateTimeImmutable())->setTimestamp($value['expires']), is_string($value['return'] ?? null) ? $value['return'] : null);
    }

    private function path(string $hash): string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            throw new RuntimeException('A sign-in link\'s hash is 64 hexadecimal characters.');
        }

        return $this->folder() . '/' . $hash;
    }

    private function folder(): string
    {
        if (!is_dir($this->folder) && !mkdir($this->folder, 0700, true) && !is_dir($this->folder)) {
            throw new RuntimeException('The folder for sign-in links could not be made.');
        }

        return $this->folder;
    }
}
