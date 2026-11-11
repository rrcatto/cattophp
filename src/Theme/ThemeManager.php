<?php

declare(strict_types=1);

namespace CattoPHP\Theme;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use RuntimeException;
use ZipArchive;

/**
 * The installed themes: found in their folders, installed side by side by name and version, activated, previewed and
 * uninstalled, with their browser files copied into the web root.
 *
 * - The folders are what counts. The project's own themes ship in its code (`bundled`); the default among them is read
 *   from there and never copied, and it wins over a stale installed copy of the same version, so a repaired default is
 *   what is served. Other themes are installed into the site's folder (`installed`) as `<slug>-v<version>`, each release
 *   immutable: installing the same release again is refused until it is uninstalled.
 * - A child theme names one exact parent release and inherits from it alone; a child cannot be a parent. Its templates
 *   are searched before its parent's (themeTemplateRoots()) and its browser files are laid over its parent's.
 * - A theme's browser files are served from the web root's `themes/<key>/`, each address carrying a fingerprint of the
 *   file served, so a repaired file reaches a browser that cached the old one.
 * - Which theme is active is kept by the project (ThemeChoice); an optional index of the installed themes too
 *   (ThemeRegistry), rebuilt from the folders. Installing, activating and uninstalling are recorded in the activity log.
 */
final class ThemeManager
{
    /**
     * @param string $bundledRoot The project's own themes, in its code, each in a folder named by its slug.
     * @param string $installedRoot The site's installed themes.
     * @param string $stagingRoot Where an uploaded package waits between its check and its installation.
     * @param string $publicRoot The served web root; each theme's browser files go into its themes/ folder.
     * @param string $defaultSlug The bundled theme a site is drawn in until another is chosen, and when the chosen one has gone.
     */
    public function __construct(
        private readonly string $bundledRoot,
        private readonly string $installedRoot,
        private readonly string $stagingRoot,
        private readonly string $publicRoot,
        private readonly string $defaultSlug,
        private readonly ThemeChoice $choice,
        private readonly ActivityLog $activity,
        private readonly ?ThemeRegistry $registry = null,
        private readonly PackageValidator $validator = new PackageValidator(),
        private readonly Publisher $publisher = new Publisher(),
    ) {
    }

    public function defaultSlug(): string
    {
        return $this->defaultSlug;
    }

    public function activeTheme(): string
    {
        $configured = trim($this->choice->chosen());
        if ($configured === '' || $configured === 'default' || $configured === $this->defaultSlug) {
            return $this->defaultThemeKey();
        }
        foreach ($this->discoverInstalledThemes() as $theme) {
            if (self::text($theme['key']) === $configured || self::text($theme['filesystem_key'] ?? '') === $configured) {
                return self::text($theme['key']);
            }
        }

        return $this->defaultThemeKey();
    }

    public function defaultThemeKey(): string
    {
        $meta = self::section($this->bundledDefaultManifest(), 'theme');

        return $this->installKey(self::text($meta['slug'] ?? ''), self::text($meta['version'] ?? ''));
    }

    /**
     * The theme a request is drawn in, and whether that is a preview rather than the live one: the theme asked for, when
     * the reader may preview and it is installed, else the active one. A mistyped key never takes the site down for the
     * person who typed it.
     *
     * @return array{0:string,1:bool}
     */
    public function resolve(string $preview, bool $mayPreview): array
    {
        $active = $this->activeTheme();
        $preview = trim($preview);
        if ($preview === '' || !$mayPreview) {
            return [$active, false];
        }
        try {
            $this->theme($preview);
        } catch (RuntimeException) {
            // An invalid preview does not change the live theme.
            return [$active, false];
        }

        return [$preview, $preview !== $active];
    }

    /**
     * Every installed theme, parents each followed by their children, with whether it is active, the default, a parent,
     * and its place in the index.
     *
     * @return list<array<string,mixed>>
     */
    public function themes(): array
    {
        $active = $this->activeTheme();
        $discovered = $this->discoverInstalledThemes();
        $registry = $this->registry?->byInstallKey();
        $byNameVersion = [];
        foreach ($discovered as $theme) {
            $byNameVersion[strtolower(self::text($theme['name'])) . "\0" . self::text($theme['version'])] = self::text($theme['key']);
        }

        $children = [];
        $itemsByKey = [];
        foreach ($discovered as $theme) {
            $key = self::text($theme['key']);
            $parentKey = '';
            if ((bool) $theme['is_child']) {
                $parentKey = $byNameVersion[strtolower(self::text($theme['parent_name'])) . "\0" . self::text($theme['parent_version'])] ?? '';
                if ($parentKey !== '') $children[$parentKey][] = $key;
            }
            $row = $registry === null ? null : ($registry[$key] ?? null);
            $theme['registry_id'] = is_array($row) ? (int) self::text($row['id'] ?? 0) : 0;
            $theme['registry_synced'] = $registry === null || (is_array($row) && hash_equals(self::text($row['manifest_sha256'] ?? ''), self::text($theme['manifest_sha256'])));
            $theme['active'] = $key === $active;
            $theme['status'] = $theme['active'] ? 'active' : 'installed';
            $theme['label'] = self::text($theme['name']);
            $theme['is_default'] = $key === $this->defaultThemeKey();
            $theme['palette_count'] = count((array) (self::section($theme, 'manifest')['palettes'] ?? []));
            $theme['palette_warning'] = $theme['palette_count'] > 5;
            $theme['parent_key'] = $parentKey;
            $itemsByKey[$key] = $theme;
        }
        foreach ($itemsByKey as $key => &$theme) {
            $theme['is_parent'] = isset($children[$key]);
        }
        unset($theme);

        $sort = static fn (string $a, string $b): int => [strtolower(self::text($itemsByKey[$a]['name'])), self::text($itemsByKey[$a]['version'])] <=> [strtolower(self::text($itemsByKey[$b]['name'])), self::text($itemsByKey[$b]['version'])];
        $roots = array_values(array_filter(array_keys($itemsByKey), static fn (string $key): bool => !(bool) $itemsByKey[$key]['is_child']));
        usort($roots, $sort);
        $ordered = [];
        foreach ($roots as $rootKey) {
            $ordered[] = $itemsByKey[$rootKey];
            $childKeys = $children[$rootKey] ?? [];
            usort($childKeys, $sort);
            foreach ($childKeys as $childKey) $ordered[] = $itemsByKey[$childKey];
        }
        // A child whose parent is missing is still shown, so it can be repaired or uninstalled.
        foreach ($itemsByKey as $theme) {
            if ((bool) $theme['is_child'] && $theme['parent_key'] === '') $ordered[] = $theme;
        }

        return $ordered;
    }

    /** Whether the index no longer matches the folders (never, without an index). */
    public function registryOutOfSync(): bool
    {
        if ($this->registry === null) return false;
        $filesystem = $this->discoverInstalledThemes();
        $registry = $this->registry->byInstallKey();
        if (count($filesystem) !== count($registry)) return true;
        foreach ($filesystem as $theme) {
            $key = self::text($theme['key']);
            if (!isset($registry[$key])) return true;
            if (!hash_equals(self::text($registry[$key]['manifest_sha256'] ?? ''), self::text($theme['manifest_sha256']))) return true;
        }

        return false;
    }

    /** Rebuilds the index from the folders; recorded when a person asked for it. */
    public function resyncRegistry(?Actor $by = null): void
    {
        if ($this->registry === null) return;
        $themes = $this->discoverInstalledThemes();
        $this->registry->rebuild($themes);
        if ($by !== null) {
            $this->activity->record('theme.registry_resynchronised', ['theme_count' => count($themes)], actor: $by);
        }
    }

    /** @return array<string,mixed> */
    public function theme(string $key): array
    {
        $key = $this->normaliseKey($key);
        $root = $this->themeRootForKey($key);
        $manifest = $this->validator->validateInstalledDirectory($root);
        $meta = self::section($manifest, 'theme');
        $parent = is_array($manifest['parent'] ?? null) ? $manifest['parent'] : null;
        $parentTheme = $parent !== null ? $this->findByNameVersion(self::text($parent['name'] ?? ''), self::text($parent['version'] ?? '')) : null;
        if ($parent !== null) {
            if ($parentTheme === null) {
                throw new RuntimeException('The child theme parent is not installed: ' . self::text($parent['name'] ?? '') . ' ' . self::text($parent['version'] ?? '') . '.');
            }
            if (is_array(self::section($parentTheme, 'manifest')['parent'] ?? null)) {
                throw new RuntimeException('Child themes cannot inherit from another child theme.');
            }
        }

        return [
            'key' => $key,
            'slug' => self::text($meta['slug'] ?? ''),
            'name' => self::text($meta['name'] ?? ''),
            'version' => self::text($meta['version'] ?? ''),
            'author' => self::text($meta['author'] ?? ''),
            'description' => self::text($meta['description'] ?? ''),
            'color_scheme' => ($meta['color_scheme'] ?? 'light') === 'dark' ? 'dark' : 'light',
            'manifest' => $manifest,
            'manifest_sha256' => hash_file('sha256', $root . '/theme.json') ?: '',
            'root' => $root,
            'asset_url' => $this->themeAssetUrl($key),
            'parent' => $parent,
            'parent_key' => $parentTheme !== null ? self::text($parentTheme['key']) : '',
            'parent_name' => $parent !== null ? self::text($parent['name'] ?? '') : '',
            'parent_version' => $parent !== null ? self::text($parent['version'] ?? '') : '',
            'is_child' => $parent !== null,
            'is_default' => $key === $this->defaultThemeKey(),
        ];
    }

    /**
     * Checks an uploaded package and keeps a copy for installImport(): what it is, what installing it would do, and the
     * token that names the copy.
     *
     * @return array<string,mixed>
     */
    public function stageImport(string $uploadedPath): array
    {
        $inspection = $this->validator->inspect($uploadedPath);
        $manifest = $inspection['manifest'];
        $this->assertInstallableManifest($manifest);

        $token = bin2hex(random_bytes(16));
        $dir = rtrim($this->stagingRoot, '/');
        // The folder is the site's, so its ownership is the installation's: a copy made as another user leaves it
        // unwritable to PHP. The page names the folder and what to do; PHP's own reason goes to the error log.
        $unwritable = 'Unable to stage the theme package for inspection: the web server cannot write to ' . $dir . '. Make that folder writable by the PHP-FPM user.';
        try {
            $this->ensureDirectory($dir);
        } catch (RuntimeException $failed) {
            error_log('Theme import: ' . $failed->getMessage());
            throw new RuntimeException($unwritable, 0, $failed);
        }
        if (!is_writable($dir)) {
            error_log('Theme import: ' . $dir . ' is not writable by the PHP process.');
            throw new RuntimeException($unwritable);
        }
        foreach (glob($dir . '/*.zip') ?: [] as $old) {
            if (is_file($old) && filemtime($old) !== false && filemtime($old) < time() - 7200) @unlink($old);
        }
        $target = $dir . '/' . $token . '.zip';
        if (!@copy($uploadedPath, $target)) {
            error_log('Theme import: ' . (error_get_last()['message'] ?? 'copy into ' . $dir . ' failed.'));
            throw new RuntimeException($unwritable);
        }
        $meta = self::section($manifest, 'theme');

        return $inspection + [
            'install_action' => 'install',
            'install_key' => $this->installKey(self::text($meta['slug'] ?? ''), self::text($meta['version'] ?? '')),
            'token' => $token,
        ];
    }

    /** Installs a package stageImport() kept, checked again, and returns its key. */
    public function importStaged(string $token, ?Actor $by): string
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            throw new RuntimeException('Invalid staged theme import token.');
        }
        $zipPath = rtrim($this->stagingRoot, '/') . '/' . $token . '.zip';
        if (!is_file($zipPath)) {
            throw new RuntimeException('The staged theme package has expired or no longer exists.');
        }

        $inspection = $this->validator->inspect($zipPath);
        $manifest = $inspection['manifest'];
        $this->assertInstallableManifest($manifest);
        $meta = self::section($manifest, 'theme');
        $key = $this->installKey(self::text($meta['slug'] ?? ''), self::text($meta['version'] ?? ''));
        $destination = $this->themesRoot() . '/' . $key;
        if (file_exists($destination)) {
            throw new RuntimeException('That theme installation directory already exists. Remove the installed theme before reinstalling the same release.');
        }

        $this->ensureDirectory($this->themesRoot());
        $temporary = $this->themesRoot() . '/.install-' . bin2hex(random_bytes(8));
        $this->ensureDirectory($temporary);
        try {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('Unable to reopen the staged theme package.');
            }
            try {
                foreach ($inspection['files'] as $path => $archiveName) {
                    $bytes = $zip->getFromName($archiveName);
                    if (!is_string($bytes)) {
                        throw new RuntimeException('Unable to extract theme file: ' . $path);
                    }
                    $target = $temporary . '/' . $path;
                    $this->ensureDirectory(dirname($target));
                    if (file_put_contents($target, $bytes) === false) {
                        throw new RuntimeException('Unable to write installed theme file: ' . $path);
                    }
                    @chmod($target, 0644);
                }
            } finally {
                $zip->close();
            }
            $this->validator->validateInstalledDirectory($temporary);
            if (!rename($temporary, $destination)) {
                throw new RuntimeException('Unable to complete the theme installation.');
            }
            $this->publish($key);
        } catch (\Throwable $e) {
            if (is_dir($temporary)) $this->removeDirectory($temporary);
            if (is_dir($destination)) $this->removeDirectory($destination);
            $this->publisher->removePublished($this->publicThemeRoot($key));
            throw $e;
        } finally {
            @unlink($zipPath);
        }

        $parent = is_array($manifest['parent'] ?? null) ? $manifest['parent'] : null;
        $this->resyncRegistry();
        $this->activity->record('theme.imported', [
            'theme' => self::text($meta['name'] ?? ''),
            'version' => self::text($meta['version'] ?? ''),
            'key' => $key,
            'parent_name' => $parent !== null ? self::text($parent['name'] ?? '') : '',
            'parent_version' => $parent !== null ? self::text($parent['version'] ?? '') : '',
        ], actor: $by ?? Actor::system());

        return $key;
    }

    public function activate(string $key, ?Actor $by): void
    {
        $key = $this->normaliseKey($key);
        $theme = $this->theme($key);
        if ((bool) $theme['is_child']) {
            $css = (string) @file_get_contents(self::text($theme['root']) . '/public/css/theme.css');
            if (trim($css) === '') {
                throw new RuntimeException('An empty child theme cannot be activated. public/css/theme.css must contain the child theme changes.');
            }
        }
        $this->publish($key);
        $this->choice->choose($key, $by);
        $this->activity->record('theme.activated', [
            'key' => $key,
            'theme' => self::text($theme['name']),
            'version' => self::text($theme['version']),
        ], actor: $by ?? Actor::system());
    }

    /**
     * Uninstalls one installed release; a run that acts for nobody passes no one. A child whose parent has gone is
     * uninstalled too: removing it needs nothing of its parent, and it could otherwise never leave once its parent had.
     */
    public function delete(string $key, ?Actor $by): void
    {
        $key = $this->normaliseKey($key);
        if ($key === $this->defaultThemeKey()) {
            throw new RuntimeException(self::text(self::section($this->bundledDefaultManifest(), 'theme')['name'] ?? '') . ' is the default theme and cannot be uninstalled.');
        }
        if ($this->activeTheme() === $key) {
            throw new RuntimeException('Activate another theme before uninstalling this one.');
        }
        $theme = $this->installedRelease($key);
        $this->removeDirectory(self::text($theme['root']));
        $this->publisher->removePublished($this->publicThemeRoot($key));
        $this->resyncRegistry();
        $this->activity->record('theme.uninstalled', [
            'key' => $key,
            'theme' => self::text($theme['name']),
            'version' => self::text($theme['version']),
        ], actor: $by ?? Actor::system());
    }

    public function themeRoot(string $key): string
    {
        return self::text($this->theme($key)['root']);
    }

    /**
     * The theme's template folders, most specific first: a child release before its parent, so a child that ships only
     * a footer inherits every other template (Templates::use()).
     *
     * @return list<string>
     */
    public function themeTemplateRoots(string $key): array
    {
        $roots = [];
        foreach (array_reverse($this->themeLayers($key)) as $layer) {
            $roots[] = rtrim(self::text($layer['root']), '/');
        }

        return $roots;
    }

    public function themeAssetUrl(string $key): string
    {
        return '/themes/' . rawurlencode($this->normaliseKey($key));
    }

    /**
     * A file of the project's own in the web root, by its address with a fingerprint of its contents, so a repaired file
     * reaches a browser that cached the old one without the release's version changing. A file that is not there is
     * fingerprinted by $fallbackVersion.
     */
    public function platformAssetUrl(string $path, string $fallbackVersion): string
    {
        $relative = '/' . ltrim($path, '/');

        return $relative . '?v=' . $this->assetFingerprint(rtrim($this->publicRoot, '/') . $relative, $fallbackVersion);
    }

    /**
     * The theme's stylesheets, each layer's theme.css and listed styles (a parent's before its child's), then its
     * palettes' stylesheet when it has palettes.
     *
     * @return list<string>
     */
    public function themeStyles(string $key): array
    {
        $urls = [];
        foreach ($this->themeLayers($key) as $layer) {
            $urls[] = $this->versionedThemeAssetUrl($layer, 'css/theme.css');
            foreach ((array) (self::section($layer, 'manifest')['styles'] ?? []) as $path) {
                $urls[] = $this->versionedThemeAssetUrl($layer, self::text($path));
            }
        }
        $active = $this->theme($key);
        $palettes = self::section($active, 'manifest')['palettes'] ?? null;
        if (is_array($palettes) && count($palettes) >= 1) {
            $urls[] = $this->versionedThemeAssetUrl($active, 'catto-palette.css');
        }

        return array_values(array_unique($urls));
    }

    /** @return list<string> */
    public function themeScripts(string $key): array
    {
        $urls = [];
        foreach ($this->themeLayers($key) as $layer) {
            foreach ((array) (self::section($layer, 'manifest')['scripts'] ?? []) as $path) {
                $urls[] = $this->versionedThemeAssetUrl($layer, self::text($path));
            }
        }

        return array_values(array_unique($urls));
    }

    /** @return list<string> */
    public function externalStyles(string $key): array
    {
        return $this->externalResources($key, 'styles');
    }

    /** @return list<string> */
    public function externalScripts(string $key): array
    {
        return $this->externalResources($key, 'scripts');
    }

    /** @return array<string,mixed> */
    public function paletteConfiguration(string $key): array
    {
        return Palettes::configuration($this->theme($key));
    }

    /** Copies the theme's browser files into the web root, a child's over its parent's, with its palettes' stylesheet. */
    public function publish(string $key): void
    {
        $theme = $this->theme($key);
        $parentRoot = null;
        if ((bool) $theme['is_child']) {
            $parentRoot = self::text($this->theme(self::text($theme['parent_key']))['root']);
        }
        $publicRoot = $this->publicThemeRoot($key);
        $this->publisher->publish(self::text($theme['root']), $publicRoot, $parentRoot);
        $this->writeGeneratedPaletteCss($key, $publicRoot);
    }

    /**
     * Every theme shipped in the project's code, by slug, parents before children: a child cannot install until the
     * exact parent it names is installed, and a directory listing's order is not a dependency order.
     *
     * @return list<string>
     */
    public function bundledThemeSlugs(): array
    {
        $root = rtrim($this->bundledRoot, '/');
        $standalone = [];
        $children = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) continue;
            $manifestPath = $root . '/' . $entry . '/theme.json';
            if (!is_dir($root . '/' . $entry) || !is_file($manifestPath)) continue;
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($manifest)) continue;
            if (is_array($manifest['parent'] ?? null)) $children[] = $entry; else $standalone[] = $entry;
        }
        sort($standalone);
        sort($children);

        return array_merge($standalone, $children);
    }

    /** The folder one bundled theme ships in. */
    public function bundledThemeRoot(string $slug): string
    {
        return rtrim($this->bundledRoot, '/') . '/' . $slug;
    }

    /**
     * Installs one bundled theme into the site, if it is not there already, and says what happened: 'installed',
     * 'replaced', 'skipped' (already there) or 'bundled' (the default, which needs no copy).
     *
     * Already there is a skip, not an error: an installed theme is an immutable release, so a routine install must leave
     * one exactly as it is. `$force` is for the deliberate case, a rebuild during development, and says so. The default
     * theme is never copied: it is read from the code and preferred over any installed copy.
     */
    public function installBundledTheme(string $slug, bool $force = false): string
    {
        $source = $this->bundledThemeRoot($slug);
        $meta = self::section($this->validator->validateInstalledDirectory($source), 'theme');
        $key = $this->installKey(self::text($meta['slug'] ?? ''), self::text($meta['version'] ?? ''));

        if ($source === $this->bundledDefaultRoot()) {
            $this->publish($this->defaultThemeKey());

            return 'bundled';
        }

        $destination = $this->themesRoot() . '/' . $key;
        if (is_dir($destination) && !$force) {
            // Published anyway: the web root's copy can be missing or stale while the installed theme is present.
            $this->publish($key);

            return 'skipped';
        }

        $replaced = is_dir($destination);
        $this->ensureDirectory($this->themesRoot());
        $suffix = bin2hex(random_bytes(8));
        $temporary = $this->themesRoot() . '/.install-' . $suffix;
        $superseded = $this->themesRoot() . '/.replaced-' . $suffix;
        try {
            $this->copyTree($source, $temporary);
            // Checked again from the copy: what is installed is what was written.
            $this->validator->validateInstalledDirectory($temporary);
            // The old release is moved aside and deleted only once the new one is in place, so a failure leaves either
            // the old release or the new one, never a theme that is neither.
            if ($replaced && !rename($destination, $superseded)) {
                throw new RuntimeException('Unable to set the installed theme aside: ' . $slug . '.');
            }
            if (!rename($temporary, $destination)) {
                if ($replaced) rename($superseded, $destination);
                throw new RuntimeException('Unable to install the bundled theme: ' . $slug . '.');
            }
        } catch (\Throwable $e) {
            $this->removeDirectory($temporary);
            throw $e;
        }
        // Past the swap, so a failure to tidy up is not a failed install; the leftover is hidden by its leading dot.
        if ($replaced) {
            try {
                $this->removeDirectory($superseded);
            } catch (\Throwable) {
            }
        }

        $this->publish($key);

        return $replaced ? 'replaced' : 'installed';
    }

    /** @param array<string,mixed> $manifest */
    private function assertInstallableManifest(array $manifest): void
    {
        $meta = self::section($manifest, 'theme');
        $name = self::text($meta['name'] ?? '');
        $version = self::text($meta['version'] ?? '');
        if ($this->findByNameVersion($name, $version) !== null) {
            throw new RuntimeException('Theme ' . $name . ' ' . $version . ' is already installed. Uninstall that exact release before reinstalling it.');
        }
        $key = $this->installKey(self::text($meta['slug'] ?? ''), $version);
        if (file_exists($this->themesRoot() . '/' . $key)) {
            throw new RuntimeException('The theme installation key already exists: ' . $key . '.');
        }

        $parent = is_array($manifest['parent'] ?? null) ? $manifest['parent'] : null;
        if ($parent === null) return;
        $installedParent = $this->findByNameVersion(self::text($parent['name'] ?? ''), self::text($parent['version'] ?? ''));
        if ($installedParent === null) {
            throw new RuntimeException('Install the exact parent theme ' . self::text($parent['name'] ?? '') . ' ' . self::text($parent['version'] ?? '') . ' before installing this child theme.');
        }
        if (is_array(self::section($installedParent, 'manifest')['parent'] ?? null)) {
            throw new RuntimeException('Child themes cannot inherit from another child theme.');
        }
    }

    /** @return list<array<string,mixed>> */
    private function themeLayers(string $key): array
    {
        $theme = $this->theme($key);
        if (!(bool) $theme['is_child']) return [$theme];

        return [$this->theme(self::text($theme['parent_key'])), $theme];
    }

    /**
     * A theme file's address, fingerprinted from the copy the web server serves rather than the installed source, so a
     * same-version repair invalidates the file actually served.
     *
     * @param array<string,mixed> $layer
     */
    private function versionedThemeAssetUrl(array $layer, string $path): string
    {
        $relative = ltrim($path, '/');
        $file = rtrim($this->publicRoot, '/') . '/themes/' . self::text($layer['key']) . '/' . $relative;

        return $this->themeAssetUrl(self::text($layer['key'])) . '/' . $relative . '?v=' . $this->assetFingerprint($file, self::text($layer['manifest_sha256'] ?? ''));
    }

    private function assetFingerprint(string $file, string $fallback): string
    {
        if (is_file($file)) {
            $hash = hash_file('sha256', $file);
            if ($hash !== false) {
                return substr($hash, 0, 16);
            }
        }

        return substr(hash('sha256', $fallback), 0, 16);
    }

    /** @return list<string> */
    private function externalResources(string $key, string $family): array
    {
        $urls = [];
        foreach ($this->themeLayers($key) as $layer) {
            $external = self::section(self::section($layer, 'manifest'), 'external');
            foreach ((array) ($external[$family] ?? []) as $url) $urls[] = self::text($url);
        }

        return array_values(array_unique($urls));
    }

    /** @return array<string,mixed>|null */
    private function findByNameVersion(string $name, string $version): ?array
    {
        foreach ($this->discoverInstalledThemes() as $theme) {
            if (strcasecmp(self::text($theme['name']), $name) === 0 && self::text($theme['version']) === $version) return $theme;
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    private function discoverInstalledThemes(): array
    {
        $itemsByKey = [];
        $root = $this->themesRoot();
        if (is_dir($root)) {
            foreach (scandir($root) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) continue;
                $container = $root . '/' . $entry;
                if (!is_dir($container)) continue;

                // An install puts theme.json directly in its versioned folder. Older releases could leave one enclosing
                // package folder behind; that layout is accepted too, so a rebuild from the folders can recover it.
                $candidates = [$container];
                foreach (scandir($container) ?: [] as $child) {
                    if ($child === '.' || $child === '..' || str_starts_with($child, '.')) continue;
                    $nested = $container . '/' . $child;
                    if (is_dir($nested) && is_file($nested . '/theme.json')) $candidates[] = $nested;
                }

                foreach ($candidates as $dir) {
                    if (!is_file($dir . '/theme.json')) continue;
                    try {
                        $manifest = $this->validator->validateInstalledDirectory($dir);
                    } catch (\Throwable) {
                        continue;
                    }
                    $meta = self::section($manifest, 'theme');
                    $key = $this->installKey(self::text($meta['slug'] ?? ''), self::text($meta['version'] ?? ''));
                    $item = $this->themeRecord($key, $dir, $manifest);
                    $item['filesystem_key'] = $entry;
                    // The canonical folder wins when an older layout describes the same release too.
                    if (!isset($itemsByKey[$key]) || basename($dir) === $key) {
                        $itemsByKey[$key] = $item;
                    }
                }
            }
        }

        // The default theme of the code that is running is read from the code and preferred over any installed copy of
        // its exact version, so a stale copy cannot shadow a repaired default. Its older versions stay discoverable.
        $defaultKey = $this->defaultThemeKey();
        $dir = $this->bundledDefaultRoot();
        $item = $this->themeRecord($defaultKey, $dir, $this->validator->validateInstalledDirectory($dir));
        $item['filesystem_key'] = $defaultKey;
        $itemsByKey[$defaultKey] = $item;

        return array_values($itemsByKey);
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    private function themeRecord(string $key, string $root, array $manifest): array
    {
        $meta = self::section($manifest, 'theme');
        $parent = is_array($manifest['parent'] ?? null) ? $manifest['parent'] : null;

        return [
            'key' => $key,
            'slug' => self::text($meta['slug'] ?? ''),
            'name' => self::text($meta['name'] ?? ''),
            'version' => self::text($meta['version'] ?? ''),
            'author' => self::text($meta['author'] ?? ''),
            'description' => self::text($meta['description'] ?? ''),
            'color_scheme' => ($meta['color_scheme'] ?? 'light') === 'dark' ? 'dark' : 'light',
            'manifest' => $manifest,
            'manifest_sha256' => hash_file('sha256', $root . '/theme.json') ?: '',
            'root' => $root,
            'storage' => 'filesystem',
            'parent_name' => $parent !== null ? self::text($parent['name'] ?? '') : '',
            'parent_version' => $parent !== null ? self::text($parent['version'] ?? '') : '',
            'is_child' => $parent !== null,
        ];
    }

    /**
     * An installed release as the folders have it, without asking for its parent: what uninstalling it needs. theme()
     * refuses a child whose parent is missing, which is right for using a theme and wrong for removing one.
     *
     * @return array<string,mixed>
     */
    private function installedRelease(string $key): array
    {
        foreach ($this->discoverInstalledThemes() as $theme) {
            if (self::text($theme['key']) === $key || self::text($theme['filesystem_key'] ?? '') === $key) return $theme;
        }
        throw new RuntimeException('The theme is not installed: ' . $key . '.');
    }

    private function themeRootForKey(string $key): string
    {
        $key = $this->normaliseKey($key);
        foreach ($this->discoverInstalledThemes() as $theme) {
            if (self::text($theme['key']) === $key || self::text($theme['filesystem_key'] ?? '') === $key) {
                return self::text($theme['root']);
            }
        }
        if ($key === $this->defaultThemeKey()) return $this->bundledDefaultRoot();
        throw new RuntimeException('The theme is not installed: ' . $key . '.');
    }

    /** Copies one folder tree, files only, with the permissions an installed theme carries. */
    private function copyTree(string $source, string $destination): void
    {
        $this->ensureDirectory($destination);
        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) continue;
            $from = $source . '/' . $entry;
            $to = $destination . '/' . $entry;
            if (is_dir($from)) {
                $this->copyTree($from, $to);
                continue;
            }
            if (!copy($from, $to)) {
                throw new RuntimeException('Unable to copy theme file: ' . $from);
            }
            @chmod($to, 0644);
        }
    }

    private function bundledDefaultRoot(): string
    {
        return $this->bundledThemeRoot($this->defaultSlug);
    }

    /** @return array<string,mixed> */
    private function bundledDefaultManifest(): array
    {
        $manifest = json_decode((string) @file_get_contents($this->bundledDefaultRoot() . '/theme.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) throw new RuntimeException('The bundled default theme\'s theme.json is invalid.');

        /** @var array<string,mixed> $manifest */
        return $manifest;
    }

    private function themesRoot(): string
    {
        return rtrim($this->installedRoot, '/');
    }

    private function publicThemeRoot(string $key): string
    {
        return rtrim($this->publicRoot, '/') . '/themes/' . $key;
    }

    private function installKey(string $slug, string $version): string
    {
        return $slug . '-v' . (preg_replace('/[^0-9A-Za-z._-]+/', '-', $version) ?? $version);
    }

    private function normaliseKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || $key === 'default' || $key === $this->defaultSlug) return $this->defaultThemeKey();
        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $key) !== 1) {
            throw new RuntimeException('Invalid installed theme identifier.');
        }

        return $key;
    }

    private function writeGeneratedPaletteCss(string $key, string $publicRoot): void
    {
        $target = rtrim($publicRoot, '/') . '/catto-palette.css';
        $css = Palettes::stylesheet($this->paletteConfiguration($key));
        if ($css === null) {
            if (is_file($target)) @unlink($target);

            return;
        }
        if (file_put_contents($target, $css) === false) {
            throw new RuntimeException('Unable to write generated theme palette stylesheet: ' . $target);
        }
        @chmod($target, 0644);
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create directory: ' . $dir . ' (' . (error_get_last()['message'] ?? 'no reason given') . ')');
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) $this->removeDirectory($path); else @unlink($path);
        }
        if (!@rmdir($dir) && is_dir($dir)) throw new RuntimeException('Unable to remove theme directory: ' . $dir);
    }

    /**
     * A part of an array that is itself an array, or nothing.
     *
     * @param array<mixed> $values
     * @return array<string,mixed>
     */
    private static function section(array $values, string $key): array
    {
        $part = $values[$key] ?? null;

        /** @var array<string,mixed> */
        return is_array($part) ? $part : [];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) || is_bool($value) ? (string) $value : '';
    }
}
