<?php

declare(strict_types=1);

namespace CattoPHP\Theme;

use RuntimeException;
use ZipArchive;

/**
 * Checks a theme before it is installed, as a ZIP package or as an installed folder (theme package 4.0, template API 2.0).
 *
 * A theme is files only: `theme.json` (its name, slug, version, author, date and description, an optional colour scheme,
 * an optional exact parent, its extra stylesheets and scripts under `public/`, HTTPS resources from elsewhere and up to
 * any number of five-colour palettes), a non-empty `public/css/theme.css`, and for a standalone theme `base.html.twig`,
 * which declares the `page_content` and `page_body` blocks and loads the page's `platform.styles` and `platform.scripts`.
 * A page-family wrapper (`pages/<family>.html.twig`, the families the project names) extends the base and draws
 * `block('page_body')`. No PHP in any template; a theme with palettes leaves the palette switcher to Core.
 * The package format's name is the project's (`theme.package_format`), so its existing packages stay valid.
 */
final class PackageValidator
{
    public const SCHEMA_VERSION = '4.0';
    public const TEMPLATE_API = '2.0';

    // 'html' stays alongside 'twig' because a theme may ship a static fragment.
    private const TEXT_EXT = ['twig', 'html', 'css', 'js', 'json', 'txt', 'md'];
    private const ASSET_EXT = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'ico', 'avif', 'woff', 'woff2'];

    /** Words a theme-owned palette switcher writes, which a theme with palettes may not: Core draws the switcher. */
    private const PALETTE_SWITCHER_WORDS = ['data-factory-palette', 'data-palette-option', 'data-palette-button', 'palette-switcher', 'palette-dock'];

    /**
     * @param string $format The package format's name, as theme.json's "format" says it.
     * @param list<string> $pageFamilies The page families a theme may wrap (pages/<family>.html.twig).
     */
    public function __construct(private readonly string $format = 'cattophp-theme', private readonly array $pageFamilies = [])
    {
    }

    public function format(): string
    {
        return $this->format;
    }

    /** @return list<string> */
    public function pageFamilies(): array
    {
        return $this->pageFamilies;
    }

    /**
     * A package's manifest, its files by path (each with its name in the archive) and a summary for the page that shows
     * what installing it would do.
     *
     * @return array{manifest: array<string,mixed>, archive_prefix: string, files: array<string,string>, summary: array<string,mixed>}
     */
    public function inspect(string $zipPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZIP support is required to import theme packages.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The uploaded file is not a readable ZIP archive.');
        }

        try {
            if ($zip->numFiles < 2 || $zip->numFiles > 500) {
                throw new RuntimeException('Theme packages must contain between 2 and 500 files.');
            }
            $prefix = $this->archivePrefix($zip);
            $raw = $zip->getFromName($prefix . 'theme.json');
            if (!is_string($raw) || trim($raw) === '') {
                throw new RuntimeException('The theme package must contain theme.json at the archive root.');
            }
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($manifest)) {
                throw new RuntimeException('theme.json is invalid.');
            }
            /** @var array<string,mixed> $manifest */
            $this->validateManifest($manifest);

            $archive = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) continue;
                $archiveName = (string) $stat['name'];
                if ($archiveName === '' || str_ends_with($archiveName, '/')) continue;
                if ($prefix !== '' && !str_starts_with($archiveName, $prefix)) {
                    throw new RuntimeException('A theme ZIP may contain only one enclosing directory.');
                }
                $path = substr($archiveName, strlen($prefix));
                $this->assertSafePath($path);
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (!in_array($ext, array_merge(self::TEXT_EXT, self::ASSET_EXT), true)) {
                    throw new RuntimeException('Unsupported theme package file type: ' . $path);
                }
                $bytes = (int) $stat['size'];
                if ($bytes > 10 * 1024 * 1024) {
                    throw new RuntimeException('A theme file exceeds the 10 MB per-file limit: ' . $path);
                }
                $total += $bytes;
                if ($total > 50 * 1024 * 1024) {
                    throw new RuntimeException('The theme package exceeds the 50 MB uncompressed limit.');
                }
                $archive[$path] = $archiveName;
            }

            $this->assertRequiredPackageFiles($manifest, $archive, $zip);
            if (count((array) ($manifest['palettes'] ?? [])) > 0) {
                $this->assertNoThemeOwnedPaletteSwitcherInArchive($archive, $zip);
            }

            return [
                'manifest' => $manifest,
                'archive_prefix' => $prefix,
                'files' => $archive,
                'summary' => $this->summary($manifest, count($archive)),
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * An installed theme's manifest, once its folder keeps the rules.
     *
     * @return array<string,mixed>
     */
    public function validateInstalledDirectory(string $root): array
    {
        $root = rtrim($root, '/');
        $manifestPath = $root . '/theme.json';
        if (!is_file($manifestPath)) {
            throw new RuntimeException('Installed theme is missing theme.json.');
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new RuntimeException('Installed theme.json is invalid.');
        }
        /** @var array<string,mixed> $manifest */
        $this->validateManifest($manifest);
        $isChild = is_array($manifest['parent'] ?? null);
        if (!$isChild) {
            $base = $root . '/base.html.twig';
            if (!is_file($base)) {
                throw new RuntimeException('A standalone theme must contain base.html.twig.');
            }
            $this->assertBaseContract((string) file_get_contents($base), 'base.html.twig');
        } elseif (is_file($root . '/base.html.twig')) {
            $this->assertBaseContract((string) file_get_contents($root . '/base.html.twig'), 'base.html.twig');
        }

        $themeCss = $root . '/public/css/theme.css';
        if (!is_file($themeCss) || trim((string) file_get_contents($themeCss)) === '') {
            throw new RuntimeException('Every theme must contain a non-empty public/css/theme.css stylesheet.');
        }
        foreach ((array) ($manifest['styles'] ?? []) as $path) {
            if (!is_file($root . '/public/' . self::text($path))) {
                throw new RuntimeException('theme.json references a missing stylesheet: public/' . self::text($path));
            }
        }
        foreach ((array) ($manifest['scripts'] ?? []) as $path) {
            if (!is_file($root . '/public/' . self::text($path))) {
                throw new RuntimeException('theme.json references a missing script: public/' . self::text($path));
            }
        }
        foreach ($this->pageFamilies as $family) {
            $path = $root . '/pages/' . $family . '.html.twig';
            if (is_file($path)) {
                $this->assertWrapperContract((string) file_get_contents($path), 'pages/' . $family . '.html.twig');
            }
        }
        if (count((array) ($manifest['palettes'] ?? [])) > 0) {
            $this->assertNoThemeOwnedPaletteSwitcher($root);
        }

        return $manifest;
    }

    /** @param array<string,mixed> $manifest */
    public function validateManifest(array $manifest): void
    {
        if (($manifest['format'] ?? '') !== $this->format) {
            throw new RuntimeException('theme.json format must be "' . $this->format . '".');
        }
        if (($manifest['schema_version'] ?? '') !== self::SCHEMA_VERSION) {
            throw new RuntimeException('Unsupported theme package schema version. Expected ' . self::SCHEMA_VERSION . '.');
        }
        if (($manifest['template_api'] ?? '') !== self::TEMPLATE_API) {
            throw new RuntimeException('Unsupported theme template API. Expected ' . self::TEMPLATE_API . '.');
        }
        $theme = $manifest['theme'] ?? null;
        if (!is_array($theme)) {
            throw new RuntimeException('theme.json must contain a theme metadata object.');
        }
        foreach (['name', 'slug', 'version', 'author', 'created_at', 'description'] as $field) {
            if (trim(self::text($theme[$field] ?? '')) === '') {
                throw new RuntimeException('theme.json theme.' . $field . ' is required.');
            }
        }
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', self::text($theme['slug'])) !== 1) {
            throw new RuntimeException('theme.slug must be a lowercase hyphenated slug.');
        }
        if (!self::isVersion(self::text($theme['version']))) {
            throw new RuntimeException('theme.version must be a semantic version such as 1.0.0.');
        }
        if (strtotime(self::text($theme['created_at'])) === false) {
            throw new RuntimeException('theme.created_at must be an ISO date-time value.');
        }
        // Optional, light when absent: the colour scheme Bootstrap's light-dark() colours follow on this theme's pages,
        // written on <html> as data-bs-theme. A theme keeps one look; it does not follow the visitor's dark mode.
        if (array_key_exists('color_scheme', $theme) && !in_array($theme['color_scheme'], ['light', 'dark'], true)) {
            throw new RuntimeException('theme.color_scheme must be light or dark.');
        }

        $parent = $manifest['parent'] ?? null;
        if ($parent !== null) {
            if (!is_array($parent)) {
                throw new RuntimeException('theme.json parent must be null or an object.');
            }
            foreach (['name', 'version'] as $field) {
                if (trim(self::text($parent[$field] ?? '')) === '') {
                    throw new RuntimeException('theme.json parent.' . $field . ' is required.');
                }
            }
            if (!self::isVersion(self::text($parent['version']))) {
                throw new RuntimeException('parent.version must identify one exact semantic version.');
            }
            if (self::text($parent['name']) === self::text($theme['name']) && self::text($parent['version']) === self::text($theme['version'])) {
                throw new RuntimeException('A theme cannot be its own parent.');
            }
        }

        foreach ((array) ($manifest['styles'] ?? []) as $path) {
            $this->assertPublicResourcePath(self::text($path), 'css', 'Additional theme stylesheet');
            if (self::text($path) === 'css/theme.css') {
                throw new RuntimeException('Do not list css/theme.css in styles; the default stylesheet is loaded automatically.');
            }
        }
        foreach ((array) ($manifest['scripts'] ?? []) as $path) {
            $this->assertPublicResourcePath(self::text($path), 'js', 'Theme script');
        }
        $external = is_array($manifest['external'] ?? null) ? $manifest['external'] : [];
        foreach (['styles', 'scripts'] as $family) {
            foreach ((array) ($external[$family] ?? []) as $url) {
                $url = self::text($url);
                if (!str_starts_with($url, 'https://') || filter_var($url, FILTER_VALIDATE_URL) === false) {
                    throw new RuntimeException('External theme resources must be valid HTTPS URLs.');
                }
            }
        }
        if (array_key_exists('palettes', $manifest)) {
            $palettes = $manifest['palettes'];
            if (!is_array($palettes) || count($palettes) < 1) {
                throw new RuntimeException('A theme palettes declaration must contain at least one named palette.');
            }
            foreach ($palettes as $palette) {
                if (!is_array($palette) || trim(self::text($palette['name'] ?? '')) === '') {
                    throw new RuntimeException('Each theme palette requires a name.');
                }
                $colors = (array) ($palette['colors'] ?? []);
                if (count($colors) !== 5) {
                    throw new RuntimeException('Each theme palette must contain exactly five colours.');
                }
                foreach ($colors as $color) {
                    if (preg_match('/^#[0-9A-Fa-f]{6}$/', self::text($color)) !== 1) {
                        throw new RuntimeException('Theme palette colours must be six-digit hexadecimal values.');
                    }
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,string> $archive
     */
    private function assertRequiredPackageFiles(array $manifest, array $archive, ZipArchive $zip): void
    {
        $isChild = is_array($manifest['parent'] ?? null);
        if (!$isChild && !isset($archive['base.html.twig'])) {
            throw new RuntimeException('A standalone theme must contain base.html.twig.');
        }
        if (isset($archive['base.html.twig'])) {
            $base = $zip->getFromName($archive['base.html.twig']);
            $this->assertBaseContract(is_string($base) ? $base : '', 'base.html.twig');
        }
        if (!isset($archive['public/css/theme.css'])) {
            throw new RuntimeException('Every theme package must contain public/css/theme.css.');
        }
        $themeCss = $zip->getFromName($archive['public/css/theme.css']);
        if (!is_string($themeCss) || trim($themeCss) === '') {
            throw new RuntimeException('Every theme package must contain a non-empty public/css/theme.css stylesheet.');
        }
        foreach ((array) ($manifest['styles'] ?? []) as $path) {
            if (!isset($archive['public/' . self::text($path)])) {
                throw new RuntimeException('theme.json references a missing stylesheet: public/' . self::text($path));
            }
        }
        foreach ((array) ($manifest['scripts'] ?? []) as $path) {
            if (!isset($archive['public/' . self::text($path)])) {
                throw new RuntimeException('theme.json references a missing script: public/' . self::text($path));
            }
        }
        foreach ($this->pageFamilies as $family) {
            $path = 'pages/' . $family . '.html.twig';
            if (!isset($archive[$path])) continue;
            $template = $zip->getFromName($archive[$path]);
            $this->assertWrapperContract(is_string($template) ? $template : '', $path);
        }
    }

    private function assertContentSlot(string $html, string $path): void
    {
        if (str_contains($html, '<?')) {
            throw new RuntimeException('Theme templates must use Twig syntax and may not contain PHP processing instructions: ' . $path);
        }
        // The base declares the two blocks a page fills. page_content is what an optional page-family wrapper overrides;
        // page_body is what the page itself fills, and a base that declares only the outer one has nowhere to put the page.
        if (!str_contains($html, '{% block page_content %}')) {
            throw new RuntimeException($path . ' must declare {% block page_content %}.');
        }
        if (!str_contains($html, '{% block page_body %}')) {
            throw new RuntimeException($path . ' must declare {% block page_body %}, which is where the platform page renders.');
        }
    }

    private function assertBaseContract(string $html, string $path): void
    {
        $this->assertContentSlot($html, $path);
        if (!str_contains($html, 'platform.styles')) {
            throw new RuntimeException($path . ' must load platform.styles so core functionality is styled under every theme.');
        }
        if (!str_contains($html, 'platform.scripts')) {
            throw new RuntimeException($path . ' must load platform.scripts so core interactions work under every theme.');
        }
    }

    /**
     * A page-family wrapper wraps the page body; it does not replace it. Template API 2.0 composes by Twig inheritance,
     * so a wrapper extends the theme's base and renders the page's block somewhere inside whatever it adds. A wrapper that
     * does neither is a page family whose content silently disappears.
     */
    private function assertWrapperContract(string $html, string $path): void
    {
        if (str_contains($html, '<?')) {
            throw new RuntimeException('Theme templates must use Twig syntax and may not contain PHP processing instructions: ' . $path);
        }
        if (!str_contains($html, "extends '@theme/base.html.twig'")) {
            throw new RuntimeException($path . " must extend '@theme/base.html.twig'.");
        }
        if (!str_contains($html, "block('page_body')")) {
            throw new RuntimeException($path . " must render the page body with block('page_body').");
        }
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    private function summary(array $manifest, int $fileCount): array
    {
        $theme = is_array($manifest['theme'] ?? null) ? $manifest['theme'] : [];
        $parent = is_array($manifest['parent'] ?? null) ? $manifest['parent'] : null;

        return [
            'name' => self::text($theme['name'] ?? ''),
            'slug' => self::text($theme['slug'] ?? ''),
            'version' => self::text($theme['version'] ?? ''),
            'author' => self::text($theme['author'] ?? ''),
            'description' => self::text($theme['description'] ?? ''),
            'parent_name' => $parent !== null ? self::text($parent['name'] ?? '') : '',
            'parent_version' => $parent !== null ? self::text($parent['version'] ?? '') : '',
            'child' => $parent !== null,
            'files' => $fileCount,
            'additional_styles' => count((array) ($manifest['styles'] ?? [])),
            'scripts' => count((array) ($manifest['scripts'] ?? [])),
            'palettes' => count((array) ($manifest['palettes'] ?? [])),
        ];
    }

    private function archivePrefix(ZipArchive $zip): string
    {
        if ($zip->getFromName('theme.json') !== false) return '';
        $candidates = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = is_array($stat) ? (string) $stat['name'] : '';
            if (preg_match('#^([^/]+)/theme\.json$#', $name, $m) === 1) $candidates[] = $m[1] . '/';
        }
        $unique = array_values(array_unique($candidates));

        return count($unique) === 1 ? $unique[0] : '';
    }

    private function assertPublicResourcePath(string $path, string $extension, string $label): void
    {
        $this->assertSafePath($path);
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== $extension) {
            throw new RuntimeException($label . ' must end in .' . $extension . ': ' . $path);
        }
    }

    private function assertSafePath(string $path): void
    {
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || str_starts_with($path, '\\') || str_contains($path, '\\') || preg_match('#(^|/)\.\.($|/)#', $path) === 1) {
            throw new RuntimeException('Unsafe theme package path: ' . $path);
        }
    }

    /** @param array<string,string> $archive */
    private function assertNoThemeOwnedPaletteSwitcherInArchive(array $archive, ZipArchive $zip): void
    {
        foreach ($archive as $path => $archiveName) {
            if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['twig', 'html', 'css', 'js'], true)) {
                continue;
            }
            $bytes = $zip->getFromName($archiveName);
            if (is_string($bytes)) {
                $this->assertNoThemeOwnedPaletteSwitcherContent(strtolower($bytes));
            }
        }
    }

    private function assertNoThemeOwnedPaletteSwitcher(string $root): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || !in_array(strtolower($file->getExtension()), ['twig', 'html', 'css', 'js'], true)) {
                continue;
            }
            $this->assertNoThemeOwnedPaletteSwitcherContent(strtolower((string) file_get_contents($file->getPathname())));
        }
    }

    private function assertNoThemeOwnedPaletteSwitcherContent(string $contents): void
    {
        foreach (self::PALETTE_SWITCHER_WORDS as $token) {
            if (str_contains($contents, $token)) {
                throw new RuntimeException('Palette-enabled themes must use the core palette switcher exclusively; remove theme-owned palette UI/state (' . $token . ').');
            }
        }
    }

    private static function isVersion(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1;
    }

    /** A manifest value as text: a string or a number as written, anything else empty. */
    private static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }
}
