<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\MemoryActivityStore;
use CattoPHP\Theme\MemoryThemeChoice;
use CattoPHP\Theme\PackageValidator;
use CattoPHP\Theme\ThemeManager;
use CattoPHP\Theme\ThemeRegistry;
use Symfony\Component\Clock\MockClock;
use ZipArchive;

/**
 * A throwaway site for the theme tests: a bundled folder holding the default theme ("Plain", slug plain), an installed
 * folder, a staging folder and a web root, all under one temporary folder that remove() deletes.
 */
final class ThemeFolders
{
    public const FORMAT = 'cattophp-theme';
    public const BASE = '<!doctype html><html><head>{% for style in platform.styles %}<link rel="stylesheet" href="{{ style }}">{% endfor %}</head><body>{% block page_content %}{% block page_body %}{% endblock %}{% endblock %}{% for script in platform.scripts %}<script src="{{ script }}"></script>{% endfor %}</body></html>';

    public readonly string $root;
    public readonly MemoryThemeChoice $choice;
    public readonly MemoryActivityStore $activityStore;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/cattophp-themes-' . bin2hex(random_bytes(6));
        foreach (['bundled', 'installed', 'staging', 'public'] as $folder) {
            mkdir($this->root . '/' . $folder, 0775, true);
        }
        $this->choice = new MemoryThemeChoice();
        $this->activityStore = new MemoryActivityStore();
        $this->write($this->root . '/bundled/plain', 'Plain', 'plain', '1.2.0', 'body{color:#222}');
    }

    /** @param list<string> $families */
    public function manager(?ThemeRegistry $registry = null, array $families = []): ThemeManager
    {
        return new ThemeManager(
            $this->root . '/bundled',
            $this->root . '/installed',
            $this->root . '/staging',
            $this->root . '/public',
            'plain',
            $this->choice,
            new ActivityLog($this->activityStore, new MockClock('2026-11-11 08:00:00')),
            $registry,
            new PackageValidator(self::FORMAT, $families),
        );
    }

    /**
     * A theme folder: theme.json, a standalone theme's base, theme.css, and any other files by path.
     *
     * @param array{name:string,version:string}|null $parent
     * @param list<array{name:string,colors:list<string>}>|null $palettes
     * @param array<string,string> $files
     */
    public function write(string $dir, string $name, string $slug, string $version, string $css, ?array $parent = null, ?array $palettes = null, array $files = []): void
    {
        foreach ($files + self::files($name, $slug, $version, $css, $parent, $palettes) as $path => $contents) {
            if (!is_dir(dirname($dir . '/' . $path))) mkdir(dirname($dir . '/' . $path), 0775, true);
            file_put_contents($dir . '/' . $path, $contents);
        }
    }

    /**
     * The same theme as a package, at $path.
     *
     * @param array{name:string,version:string}|null $parent
     * @param array<string,string> $files
     */
    public function package(string $name, string $slug, string $version, string $css, ?array $parent = null, array $files = []): string
    {
        $path = $this->root . '/' . $slug . '-' . $version . '-' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files + self::files($name, $slug, $version, $css, $parent, null) as $file => $contents) {
            $zip->addFromString($file, $contents);
        }
        $zip->close();

        return $path;
    }

    public function remove(): void
    {
        self::removeDirectory($this->root);
    }

    /**
     * @param array{name:string,version:string}|null $parent
     * @param list<array{name:string,colors:list<string>}>|null $palettes
     * @return array<string,string>
     */
    private static function files(string $name, string $slug, string $version, string $css, ?array $parent, ?array $palettes): array
    {
        $manifest = [
            'format' => self::FORMAT,
            'schema_version' => '4.0',
            'template_api' => '2.0',
            'theme' => ['name' => $name, 'slug' => $slug, 'version' => $version, 'author' => 'Test', 'description' => 'A test theme.', 'created_at' => '2026-11-11T08:00:00+02:00'],
            'parent' => $parent,
            'styles' => [],
            'scripts' => [],
            'external' => ['styles' => [], 'scripts' => []],
        ];
        if ($palettes !== null) $manifest['palettes'] = $palettes;
        $files = ['theme.json' => (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)];
        if ($parent === null) $files['base.html.twig'] = self::BASE;
        $files['public/css/theme.css'] = $css;

        return $files;
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        @chmod($dir, 0775);
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            if (is_dir($path)) self::removeDirectory($path); else @unlink($path);
        }
        @rmdir($dir);
    }
}
