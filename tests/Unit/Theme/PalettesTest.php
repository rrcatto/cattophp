<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Theme;

use CattoPHP\Tests\Support\ThemeFolders;
use CattoPHP\Theme\Palettes;
use PHPUnit\Framework\TestCase;

/** A theme's palettes: darkest to lightest, a switcher only for two or more, and the stylesheet they make (ported from cattolms's ThemePaletteTest). */
final class PalettesTest extends TestCase
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

    public function testDeclaredPalettesAreSortedDarkestToLightestAndPublished(): void
    {
        $this->site->write($this->site->root . '/installed/fixture-v1.0.0', 'Fixture', 'fixture', '1.0.0', 'body{font-family:sans-serif}', palettes: [
            ['name' => 'One', 'colors' => ['#FFFFFF', '#000000', '#888888', '#222222', '#CCCCCC']],
            ['name' => 'Two', 'colors' => ['#EEEEEE', '#111111', '#AAAAAA', '#444444', '#777777']],
            ['name' => 'Three', 'colors' => ['#F5F5F5', '#101010', '#BBBBBB', '#555555', '#888888']],
            ['name' => 'Four', 'colors' => ['#FFFFFF', '#050505', '#999999', '#444444', '#DDDDDD']],
        ]);
        $manager = $this->site->manager();
        $configuration = $manager->paletteConfiguration('fixture-v1.0.0');

        self::assertTrue($configuration['enabled']);
        self::assertTrue($configuration['switcher_visible']);
        self::assertSame(4, $configuration['palette_count']);
        self::assertSame(['#000000', '#222222', '#888888', '#CCCCCC', '#FFFFFF'], $configuration['palettes'][0]['colors']);
        self::assertSame(['#FFFFFF', '#FFFFFF', '#000000', '#000000', '#000000'], $configuration['palettes'][0]['foregrounds']);

        $manager->publish('fixture-v1.0.0');
        $css = (string) file_get_contents($this->site->root . '/public/themes/fixture-v1.0.0/catto-palette.css');
        self::assertStringContainsString('--cl-darkest:var(--cl-color-1)', $css);
        self::assertStringContainsString('--cl-page-bg:var(--cl-color-5)', $css);
        self::assertStringContainsString('--cl-button-border:var(--cl-color-2)', $css);
        self::assertStringContainsString('[data-theme-nav] summary', $css);
        self::assertStringContainsString('.cl-header .cl-nav-panel{color:var(--cl-darkest)}', $css);
        self::assertStringNotContainsString('] .cl-nav-panel{color:var(--cl-darkest)}', $css, 'Sidebar panels inherit their dark rail contrast.');
        self::assertStringContainsString('[data-nav-item].is-active:not(details)', $css, 'Active dropdowns paint their summary, not the entire panel.');
        self::assertDoesNotMatchRegularExpression('/\\.(?:btn-primary|card|stat-card|modal-card)\\b/', $css);
        // Each rule matches the server's class too, so the first paint is already in the reader's palette.
        self::assertStringContainsString('body[data-cl-palette-managed="1"][data-cl-palette="3"],body.cl-palette-managed.cl-palette-3{', $css);
        self::assertContains('/themes/fixture-v1.0.0/catto-palette.css?v=' . substr(hash('sha256', $css), 0, 16), $manager->themeStyles('fixture-v1.0.0'));
    }

    public function testOnePaletteIsAppliedWithoutASwitcher(): void
    {
        $this->site->write($this->site->root . '/installed/single-v1.0.0', 'Single', 'single', '1.0.0', 'body{}', palettes: [
            ['name' => 'Only', 'colors' => ['#FFFFFF', '#000000', '#888888', '#222222', '#CCCCCC']],
        ]);
        $manager = $this->site->manager();
        $configuration = $manager->paletteConfiguration('single-v1.0.0');

        self::assertTrue($configuration['enabled']);
        self::assertFalse($configuration['switcher_visible']);
        $manager->publish('single-v1.0.0');
        self::assertFileExists($this->site->root . '/public/themes/single-v1.0.0/catto-palette.css');
    }

    public function testAThemeWithoutPalettesKeepsItsOwnColours(): void
    {
        $manager = $this->site->manager();
        mkdir($this->site->root . '/public/themes/plain-v1.2.0', 0775, true);
        file_put_contents($this->site->root . '/public/themes/plain-v1.2.0/catto-palette.css', 'left over');

        self::assertFalse($manager->paletteConfiguration('plain-v1.2.0')['enabled']);
        self::assertNull(Palettes::stylesheet($manager->paletteConfiguration('plain-v1.2.0')));
        $manager->publish('plain-v1.2.0');
        self::assertFileDoesNotExist($this->site->root . '/public/themes/plain-v1.2.0/catto-palette.css', 'A stylesheet left from palettes the theme no longer has is removed.');
    }

    public function testTheForegroundIsWhicheverContrastsMore(): void
    {
        self::assertSame('#FFFFFF', Palettes::contrastForeground('#2A324B'));
        self::assertSame('#000000', Palettes::contrastForeground('#F7C59F'));
        self::assertEqualsWithDelta(1.0, Palettes::relativeLuminance('#FFFFFF'), 0.0001);
        self::assertEqualsWithDelta(0.0, Palettes::relativeLuminance('#000000'), 0.0001);
    }
}
