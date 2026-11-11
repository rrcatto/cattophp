<?php

declare(strict_types=1);

namespace CattoPHP\Theme;

use RuntimeException;

/**
 * Puts a theme's browser files where the web server serves them: its `public/` folder copied into the web root's
 * `themes/<key>/`, replacing what was there. A child theme's copy starts from its parent's files with its own laid over
 * them, so the child's stylesheet can name its parent's pictures and fonts; nothing is linked, everything is copied.
 */
final class Publisher
{
    public function publish(string $themeRoot, string $targetRoot, ?string $parentRoot = null): void
    {
        $source = rtrim($themeRoot, '/') . '/public';
        if (!is_dir($source)) {
            throw new RuntimeException('Theme public directory does not exist: ' . $source);
        }
        if ($parentRoot !== null && !is_dir(rtrim($parentRoot, '/') . '/public')) {
            throw new RuntimeException('Parent theme public directory does not exist.');
        }
        $targetRoot = rtrim($targetRoot, '/');
        if (is_dir($targetRoot)) {
            $this->removeDirectory($targetRoot);
        }
        if ($parentRoot !== null) {
            $this->copyDirectory(rtrim($parentRoot, '/') . '/public', $targetRoot);
            $this->copyDirectory($source, $targetRoot, false);
        } else {
            $this->copyDirectory($source, $targetRoot);
        }
    }

    public function removePublished(string $targetRoot): void
    {
        if (is_dir($targetRoot)) {
            $this->removeDirectory($targetRoot);
        }
    }

    private function copyDirectory(string $source, string $target, bool $create = true): void
    {
        if (($create || !is_dir($target)) && !is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
            throw new RuntimeException('Unable to create published theme directory.');
        }
        $items = scandir($source);
        if ($items === false) {
            throw new RuntimeException('Unable to read theme public directory.');
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $from = $source . '/' . $item;
            $to = $target . '/' . $item;
            if (is_dir($from)) {
                $this->copyDirectory($from, $to, false);
            } elseif (!copy($from, $to)) {
                throw new RuntimeException('Unable to publish theme asset: ' . $item);
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        $items = scandir($directory);
        if ($items === false) {
            throw new RuntimeException('Unable to read theme directory for removal.');
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } elseif (!unlink($path)) {
                throw new RuntimeException('Unable to remove theme file: ' . $item);
            }
        }
        if (!rmdir($directory)) {
            throw new RuntimeException('Unable to remove theme directory.');
        }
    }
}
