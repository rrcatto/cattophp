<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Theme;

use CattoPHP\Activity\Actor;
use CattoPHP\Tests\Support\ThemeFolders;
use CattoPHP\Theme\ThemeRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Installed themes: found in their folders, installed side by side, activated, previewed, uninstalled, a child laid over
 * its parent, bundled themes installed, and each change in the activity log (ported from cattolms's theme lifecycle,
 * filesystem and registry tests).
 */
final class ThemeManagerTest extends TestCase
{
    private ThemeFolders $site;

    protected function setUp(): void
    {
        $this->site = new ThemeFolders();
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testTheBundledDefaultIsActiveUntilAnotherIsChosen(): void
    {
        $manager = $this->site->manager();
        self::assertSame('plain-v1.2.0', $manager->defaultThemeKey());
        self::assertSame('plain-v1.2.0', $manager->activeTheme());
        foreach (['', 'default', 'plain', 'gone-v9.9.9'] as $chosen) {
            $this->site->choice->choose($chosen, null);
            self::assertSame('plain-v1.2.0', $manager->activeTheme(), 'Chosen: "' . $chosen . '".');
        }
        $theme = $manager->theme('default');
        self::assertSame('Plain', $theme['name']);
        self::assertTrue($theme['is_default']);
        self::assertSame($this->site->root . '/bundled/plain', $theme['root'], 'The default is read from the code, never copied.');
    }

    public function testDiscoveryFollowsTheManifestAndTheBundledDefaultWinsOverAStaleCopy(): void
    {
        $installed = $this->site->root . '/installed';
        // A stale copy of the default's own release, under another folder name, must never shadow the bundled one.
        $this->site->write($installed . '/stale-default-copy', 'Plain', 'plain', '1.2.0', 'body{color:red}');
        // An older release of the default stays discoverable beside it.
        $this->site->write($installed . '/plain-v1.0.0', 'Plain', 'plain', '1.0.0', 'body{color:#333}');
        // An older install left one enclosing package folder.
        $this->site->write($installed . '/legacy-theme-old/legacy-theme-v3.2.0', 'Legacy Theme', 'legacy-theme', '3.2.0', 'body{}');
        // A folder that is not a theme is passed over.
        mkdir($installed . '/not-a-theme');

        $themes = array_column($this->site->manager()->themes(), null, 'key');
        self::assertSame(['legacy-theme-v3.2.0', 'plain-v1.0.0', 'plain-v1.2.0'], array_keys($themes));
        self::assertSame($this->site->root . '/bundled/plain', $themes['plain-v1.2.0']['root']);
        self::assertSame('legacy-theme-old', $themes['legacy-theme-v3.2.0']['filesystem_key']);
        self::assertTrue($themes['plain-v1.2.0']['active']);
        self::assertTrue($themes['plain-v1.2.0']['registry_synced'], 'Without an index every theme is in step.');
    }

    public function testAPackageIsCheckedStagedInstalledActivatedAndRecorded(): void
    {
        $manager = $this->site->manager();
        $by = new Actor('7', 'Ada Lovelace');
        $staged = $manager->stageImport($this->site->package('Harbour', 'harbour', '2.0.0', 'body{background:#123}'));
        self::assertSame('install', $staged['install_action']);
        self::assertSame('harbour-v2.0.0', $staged['install_key']);
        self::assertSame('Harbour', $staged['summary']['name']);
        self::assertFileExists($this->site->root . '/staging/' . $staged['token'] . '.zip');

        $key = $manager->importStaged($staged['token'], $by);
        self::assertSame('harbour-v2.0.0', $key);
        self::assertFileDoesNotExist($this->site->root . '/staging/' . $staged['token'] . '.zip', 'The staged copy is removed.');
        self::assertFileExists($this->site->root . '/installed/harbour-v2.0.0/public/css/theme.css');
        self::assertFileExists($this->site->root . '/public/themes/harbour-v2.0.0/css/theme.css', 'Its browser files are published.');

        $manager->activate($key, $by);
        self::assertSame($key, $manager->activeTheme());
        self::assertSame($key, $this->site->choice->chosen());

        $entries = $this->site->activityStore->recent();
        self::assertSame(['theme.activated', 'theme.imported'], array_map(static fn ($entry): string => $entry->action, $entries));
        self::assertSame('7', $entries[0]->actorId);
        self::assertSame(['key' => 'harbour-v2.0.0', 'theme' => 'Harbour', 'version' => '2.0.0'], $entries[0]->details);
        self::assertSame(['theme' => 'Harbour', 'version' => '2.0.0', 'key' => 'harbour-v2.0.0', 'parent_name' => '', 'parent_version' => ''], $entries[1]->details);

        // The same release again is refused until it is uninstalled.
        try {
            $manager->stageImport($this->site->package('Harbour', 'harbour', '2.0.0', 'body{}'));
            self::fail('The same release was staged twice.');
        } catch (RuntimeException $refused) {
            self::assertStringContainsString('Harbour 2.0.0 is already installed', $refused->getMessage());
        }
    }

    public function testAThemeIsReinstalledAtTheSameVersionAfterItIsUninstalled(): void
    {
        $manager = $this->site->manager();
        $key = $manager->importStaged($manager->stageImport($this->site->package('Repair', 'repair', '1.0.0', 'body{outline:1px solid red}'))['token'], null);
        $manager->delete($key, null);
        self::assertDirectoryDoesNotExist($this->site->root . '/installed/' . $key);
        self::assertDirectoryDoesNotExist($this->site->root . '/public/themes/' . $key);

        $again = $manager->importStaged($manager->stageImport($this->site->package('Repair', 'repair', '1.0.0', 'body{outline:2px solid green}'))['token'], null);
        self::assertSame($key, $again);
        self::assertStringContainsString('green', (string) file_get_contents($this->site->root . '/installed/' . $key . '/public/css/theme.css'));
        self::assertNull($this->site->activityStore->recent(1)[0]->actorId, 'A run that acts for nobody is the system.');
    }

    public function testAChildIsSearchedBeforeItsParentAndLaidOverItsFiles(): void
    {
        $manager = $this->site->manager();
        $parent = $manager->importStaged($manager->stageImport($this->site->package('Dune', 'dune', '1.0.0', 'body{background:#111}', files: ['public/img/sand.svg' => '<svg/>']))['token'], null);
        $child = $manager->importStaged($manager->stageImport($this->site->package('Dune Night', 'dune-night', '1.0.0', '.night{color:gold}', ['name' => 'Dune', 'version' => '1.0.0']))['token'], null);

        $theme = $manager->theme($child);
        self::assertTrue($theme['is_child']);
        self::assertSame($parent, $theme['parent_key']);
        self::assertSame([$this->site->root . '/installed/' . $child, $this->site->root . '/installed/' . $parent], $manager->themeTemplateRoots($child));
        self::assertFileExists($this->site->root . '/public/themes/' . $child . '/img/sand.svg', 'The child is published over its parent\'s files.');
        self::assertStringContainsString('gold', (string) file_get_contents($this->site->root . '/public/themes/' . $child . '/css/theme.css'));

        $styles = $manager->themeStyles($child);
        self::assertCount(2, $styles);
        self::assertStringStartsWith('/themes/' . $parent . '/css/theme.css?v=', $styles[0], 'The parent\'s stylesheet comes first.');
        self::assertStringStartsWith('/themes/' . $child . '/css/theme.css?v=', $styles[1]);

        $listed = $manager->themes();
        self::assertSame([$parent, $child, 'plain-v1.2.0'], array_column($listed, 'key'), 'A parent is followed by its children.');
        self::assertTrue($listed[0]['is_parent']);

        // A child of a child is refused.
        try {
            $manager->stageImport($this->site->package('Grandchild', 'grandchild', '1.0.0', 'a{}', ['name' => 'Dune Night', 'version' => '1.0.0']));
            self::fail('A child of a child was staged.');
        } catch (RuntimeException $refused) {
            self::assertStringContainsString('Child themes cannot inherit from another child theme.', $refused->getMessage());
        }
        // A child whose parent is not installed is refused.
        try {
            $manager->stageImport($this->site->package('Orphan', 'orphan', '1.0.0', 'a{}', ['name' => 'Nowhere', 'version' => '1.0.0']));
            self::fail('A child without its parent was staged.');
        } catch (RuntimeException $refused) {
            self::assertStringContainsString('Install the exact parent theme Nowhere 1.0.0', $refused->getMessage());
        }
    }

    public function testABrokenThemeIsNotOfferedAndCannotBeActivated(): void
    {
        $manager = $this->site->manager();
        $manager->importStaged($manager->stageImport($this->site->package('Base', 'base', '1.0.0', 'body{}'))['token'], null);
        $this->site->write($this->site->root . '/installed/empty-child-v1.0.0', 'Empty Child', 'empty-child', '1.0.0', 'x', ['name' => 'Base', 'version' => '1.0.0']);
        file_put_contents($this->site->root . '/installed/empty-child-v1.0.0/public/css/theme.css', " \n");
        // An empty theme.css fails the folder's own check, so the theme is neither listed nor activated.
        self::assertNotContains('empty-child-v1.0.0', array_column($manager->themes(), 'key'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The theme is not installed: empty-child-v1.0.0.');
        try {
            $manager->activate('empty-child-v1.0.0', null);
        } finally {
            self::assertSame('', $this->site->choice->chosen(), 'Nothing was chosen.');
        }
    }

    public function testTheDefaultAndTheActiveThemeCannotBeUninstalledAndAnOrphanedChildCan(): void
    {
        $manager = $this->site->manager();
        try {
            $manager->delete('plain-v1.2.0', null);
            self::fail('The default theme was uninstalled.');
        } catch (RuntimeException $refused) {
            self::assertSame('Plain is the default theme and cannot be uninstalled.', $refused->getMessage());
        }

        $parent = $manager->importStaged($manager->stageImport($this->site->package('Gone', 'gone', '1.0.0', 'body{}'))['token'], null);
        $child = $manager->importStaged($manager->stageImport($this->site->package('Left', 'left', '1.0.0', '.left{color:red}', ['name' => 'Gone', 'version' => '1.0.0']))['token'], null);
        $manager->activate($parent, null);
        try {
            $manager->delete($parent, null);
            self::fail('The active theme was uninstalled.');
        } catch (RuntimeException $refused) {
            self::assertSame('Activate another theme before uninstalling this one.', $refused->getMessage());
        }

        // The parent leaves without its child, as a theme taken out of the code does.
        $manager->activate('plain-v1.2.0', null);
        $manager->delete($parent, null);
        $listed = array_column($manager->themes(), null, 'key');
        self::assertSame('', $listed[$child]['parent_key'], 'The child is still listed, with its parent missing.');
        $manager->delete($child, new Actor('3', 'Grace Hopper'));
        self::assertDirectoryDoesNotExist($this->site->root . '/installed/' . $child);
        self::assertArrayNotHasKey($child, array_column($manager->themes(), null, 'key'));
        self::assertSame('3', $this->site->activityStore->recent(1, 'theme.uninstalled')[0]->actorId);
    }

    public function testAPreviewIsOnlyForThoseWhoMayAndOnlyOfAnInstalledTheme(): void
    {
        $manager = $this->site->manager();
        $other = $manager->importStaged($manager->stageImport($this->site->package('Other', 'other', '1.0.0', 'body{}'))['token'], null);

        self::assertSame(['plain-v1.2.0', false], $manager->resolve('', true));
        self::assertSame(['plain-v1.2.0', false], $manager->resolve($other, false), 'Without permission the live theme is drawn.');
        self::assertSame([$other, true], $manager->resolve($other, true));
        self::assertSame(['plain-v1.2.0', false], $manager->resolve('no-such-v1.0.0', true), 'A mistyped key draws the live theme.');
        self::assertSame(['plain-v1.2.0', false], $manager->resolve('Not a key!', true));
        self::assertSame(['plain-v1.2.0', false], $manager->resolve('plain-v1.2.0', true), 'The live theme is no preview.');
    }

    public function testBundledThemesAreInstalledOnceAndTheDefaultIsOnlyPublished(): void
    {
        $this->site->write($this->site->root . '/bundled/coast', 'Coast', 'coast', '1.0.0', 'body{color:#036}');
        $this->site->write($this->site->root . '/bundled/coast-dusk', 'Coast Dusk', 'coast-dusk', '1.0.0', '.dusk{color:#630}', ['name' => 'Coast', 'version' => '1.0.0']);
        $manager = $this->site->manager();

        self::assertSame(['coast', 'plain', 'coast-dusk'], $manager->bundledThemeSlugs(), 'Parents before children.');
        self::assertSame('bundled', $manager->installBundledTheme('plain'));
        self::assertDirectoryDoesNotExist($this->site->root . '/installed/plain-v1.2.0', 'The default is never copied.');
        self::assertFileExists($this->site->root . '/public/themes/plain-v1.2.0/css/theme.css');

        self::assertSame('installed', $manager->installBundledTheme('coast'));
        self::assertSame('installed', $manager->installBundledTheme('coast-dusk'));
        file_put_contents($this->site->root . '/installed/coast-v1.0.0/public/css/theme.css', 'body{color:changed}');
        self::assertSame('skipped', $manager->installBundledTheme('coast'));
        self::assertStringContainsString('changed', (string) file_get_contents($this->site->root . '/installed/coast-v1.0.0/public/css/theme.css'), 'An installed release is left as it is.');
        self::assertSame('replaced', $manager->installBundledTheme('coast', true));
        self::assertStringContainsString('#036', (string) file_get_contents($this->site->root . '/installed/coast-v1.0.0/public/css/theme.css'));
        self::assertSame([], glob($this->site->root . '/installed/.*-*') ?: [], 'Nothing is left aside.');
    }

    public function testAnAssetAddressCarriesTheFingerprintOfTheFileServed(): void
    {
        $manager = $this->site->manager();
        file_put_contents($this->site->root . '/public/site.css', 'a{}');
        self::assertSame('/site.css?v=' . substr(hash('sha256', 'a{}'), 0, 16), $manager->platformAssetUrl('site.css', '1.0.0'));
        self::assertSame('/gone.css?v=' . substr(hash('sha256', '1.0.0'), 0, 16), $manager->platformAssetUrl('/gone.css', '1.0.0'), 'A missing file is fingerprinted by the release.');

        $manager->publish('plain-v1.2.0');
        self::assertSame(['/themes/plain-v1.2.0/css/theme.css?v=' . substr(hash('sha256', 'body{color:#222}'), 0, 16)], $manager->themeStyles('plain-v1.2.0'));
        self::assertSame('/themes/plain-v1.2.0', $manager->themeAssetUrl('default'));
    }

    public function testAnIndexIsRebuiltFromTheFoldersAndSaysWhenItIsOutOfStep(): void
    {
        $registry = new class implements ThemeRegistry {
            /** @var array<string,array<string,mixed>> */
            public array $rows = [];

            public function byInstallKey(): array
            {
                return $this->rows;
            }

            public function rebuild(array $themes): void
            {
                $this->rows = [];
                foreach ($themes as $number => $theme) {
                    $this->rows[(string) $theme['key']] = ['id' => $number + 1, 'manifest_sha256' => $theme['manifest_sha256']];
                }
            }
        };
        $manager = $this->site->manager($registry);
        self::assertTrue($manager->registryOutOfSync(), 'An empty index is out of step with the folders.');
        $manager->resyncRegistry(new Actor('5', 'Alan Turing'));
        self::assertFalse($manager->registryOutOfSync());
        self::assertSame(['theme_count' => 1], $this->site->activityStore->recent(1, 'theme.registry_resynchronised')[0]->details);

        // Installing rebuilds it, without an entry of its own.
        $key = $manager->importStaged($manager->stageImport($this->site->package('Indexed', 'indexed', '1.0.0', 'body{}'))['token'], null);
        self::assertArrayHasKey($key, $registry->rows);
        self::assertCount(1, $this->site->activityStore->recent(10, 'theme.registry_resynchronised'));
        $listed = array_column($manager->themes(), null, 'key');
        self::assertTrue($listed[$key]['registry_synced']);
        self::assertGreaterThan(0, $listed[$key]['registry_id']);

        file_put_contents($this->site->root . '/installed/' . $key . '/theme.json', str_replace('A test theme.', 'Changed by hand.', (string) file_get_contents($this->site->root . '/installed/' . $key . '/theme.json')));
        self::assertTrue($manager->registryOutOfSync(), 'A manifest changed by hand puts the index out of step.');
        self::assertFalse(array_column($manager->themes(), null, 'key')[$key]['registry_synced']);
    }

    public function testStagingIntoAFolderTheServerCannotWriteNamesTheFolder(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('The superuser can write to any folder.');
        }
        chmod($this->site->root . '/staging', 0555);
        $manager = $this->site->manager();
        $previous = ini_set('error_log', $this->site->root . '/error.log');
        try {
            $manager->stageImport($this->site->package('Refused', 'refused', '1.0.0', 'body{}'));
            self::fail('A theme was staged into a folder the server cannot write.');
        } catch (RuntimeException $refused) {
            self::assertStringContainsString($this->site->root . '/staging', $refused->getMessage());
            self::assertStringContainsString('writable', $refused->getMessage());
        } finally {
            ini_set('error_log', (string) $previous);
            chmod($this->site->root . '/staging', 0775);
        }
    }

    public function testAnUnknownOrMalformedKeyIsRefused(): void
    {
        $manager = $this->site->manager();
        foreach (['../etc', 'Upper-v1', 'missing-v1.0.0'] as $key) {
            try {
                $manager->theme($key);
                self::fail('Accepted ' . $key . '.');
            } catch (RuntimeException $refused) {
                self::assertMatchesRegularExpression('/Invalid installed theme identifier|The theme is not installed/', $refused->getMessage());
            }
        }
        try {
            $manager->importStaged('not-a-token', null);
            self::fail('Accepted a malformed token.');
        } catch (RuntimeException $refused) {
            self::assertSame('Invalid staged theme import token.', $refused->getMessage());
        }
        try {
            $manager->importStaged(str_repeat('a', 32), null);
            self::fail('Accepted a token with no package.');
        } catch (RuntimeException $refused) {
            self::assertSame('The staged theme package has expired or no longer exists.', $refused->getMessage());
        }
    }
}
