<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Ui;

use CattoPHP\Tests\Support\UiHarness;
use CattoPHP\Twig\UiExtension;
use CattoPHP\Ui\Components;
use CattoPHP\Ui\IconLibrary;
use CattoPHP\Ui\UploadedSvg;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class IconLibraryTest extends TestCase
{
    public function testTheLibraryIsPublishedWithItsLicence(): void
    {
        $root = dirname(__DIR__, 3) . '/resources/icons/';
        $library = json_decode((string) file_get_contents($root . 'lucide.json'), true);
        self::assertIsArray($library);
        self::assertSame(['Lucide', 'ISC'], [$library['name'], $library['license']]);
        self::assertGreaterThan(1500, count($library['icons']));
        self::assertStringContainsString('ISC License', (string) file_get_contents($root . 'LICENSE-lucide.txt'));
    }

    public function testEveryIconIsAPictureInTheThemesColour(): void
    {
        $icons = new IconLibrary();
        $library = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/resources/icons/lucide.json'), true);
        self::assertIsArray($library);
        foreach (array_keys($library['icons']) as $name) {
            $svg = (string) $icons->svg((string) $name);
            self::assertStringContainsString('stroke="currentColor"', $svg, (string) $name);
            UploadedSvg::validate($svg, UploadedSvg::ICON_BYTES, 'icon');
        }
        self::assertNull($icons->svg('no-such-icon'));
    }

    public function testASearchFindsIconsByNameThenKeyword(): void
    {
        $icons = new IconLibrary();
        self::assertSame('lock', $icons->search('lock')[0]);
        self::assertContains('scale', $icons->search('justice'), 'A keyword finds an icon too.');
        self::assertSame([], $icons->search('zzqxx'));
        self::assertSame([], $icons->search('   '));
        self::assertCount(5, $icons->search('chart', 5));
    }

    public function testANameGetsTheIconThatFitsItBestAndAProjectsSubjectsComeFirst(): void
    {
        $icons = new IconLibrary();
        foreach (['Lock' => 'lock', 'Camera' => 'camera', 'Zzqx' => 'folder'] as $name => $icon) {
            self::assertSame($icon, $icons->bestFor($name, 'folder'), $name);
        }
        $subjects = new IconLibrary(['payroll' => ['wallet'], 'sorcery' => ['no-such-icon', 'wand-sparkles']], ['workshop']);
        self::assertSame('wallet', $subjects->bestFor('Payroll', 'folder'), 'A project\'s subject before the keywords.');
        self::assertSame('wand-sparkles', $subjects->bestFor('Sorcery', 'folder'), 'An icon the library lacks is skipped.');
        self::assertSame($subjects->bestFor('Payroll', 'folder'), $subjects->bestFor('Payroll Workshop', 'folder'), 'A word the project says nothing about is left out.');
        self::assertSame(['value', 'label', 'icon'], array_keys($icons->choices('Lock', '')[0]), 'Choices are feature cards.');
        self::assertSame('camera', $icons->choices('Lock', 'camera')[0]['value'], 'A search replaces the suggestions.');
    }

    public function testOnlyTheLibrarysIconsCanBeChosenOrDrawn(): void
    {
        $icons = new IconLibrary();
        self::assertNull($icons->chosen(''));
        self::assertNull($icons->chosen(null));
        self::assertSame($icons->svg('lock'), $icons->chosen('lock'));
        $twig = new Environment(new ArrayLoader(['page' => "{{ lucide('lock')|raw }}", 'missing' => "{{ lucide('no-such-icon') }}"]));
        $twig->addExtension(new UiExtension(new Components(), $icons));
        self::assertSame($icons->svg('lock'), $twig->render('page'));
        try {
            $twig->render('missing');
            self::fail('A name the library does not have was drawn.');
        } catch (\Twig\Error\RuntimeError $error) {
            self::assertStringContainsString('The icon library has no no-such-icon.', $error->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $icons->chosen('../../etc/passwd');
    }
}
