<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Theme;

use CattoPHP\Theme\PackageValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * Theme package 4.0: a standalone theme's base, a child's exact parent, a non-empty theme.css, palettes, page-family
 * wrappers that extend the base, and no server files (ported from cattolms's ThemePackageValidatorTest).
 */
final class PackageValidatorTest extends TestCase
{
    public function testMinimalStandaloneThemeIsAccepted(): void
    {
        $this->requireZip();
        $path = $this->makePackage([
            'theme.json' => $this->manifest(),
            'base.html.twig' => $this->baseTemplate(),
            'public/css/theme.css' => 'body{margin:0}',
        ]);
        try {
            $result = $this->validator()->inspect($path);
            self::assertSame('Test Theme', $result['summary']['name']);
            self::assertFalse($result['summary']['child']);
        } finally { @unlink($path); }
    }

    public function testChildMayOmitBaseButMustProvideThemeCss(): void
    {
        $this->requireZip();
        $manifest = json_decode($this->manifest(), true, 512, JSON_THROW_ON_ERROR);
        $manifest['parent'] = ['name' => 'Parent Theme', 'version' => '1.0.1'];
        $path = $this->makePackage([
            'theme.json' => json_encode($manifest, JSON_THROW_ON_ERROR),
            'public/css/theme.css' => '.course-card{border-radius:0}',
        ]);
        try {
            $result = $this->validator()->inspect($path);
            self::assertTrue($result['summary']['child']);
            self::assertSame('Parent Theme', $result['summary']['parent_name']);
            self::assertSame('1.0.1', $result['summary']['parent_version']);
        } finally { @unlink($path); }
    }

    public function testEmptyThemeCssIsRejected(): void
    {
        $this->requireZip();
        $manifest = json_decode($this->manifest(), true, 512, JSON_THROW_ON_ERROR);
        $manifest['parent'] = ['name' => 'Parent Theme', 'version' => '1.0.1'];
        $path = $this->makePackage([
            'theme.json' => json_encode($manifest, JSON_THROW_ON_ERROR),
            'public/css/theme.css' => " \n",
        ]);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('non-empty public/css/theme.css');
            $this->validator()->inspect($path);
        } finally { @unlink($path); }
    }

    public function testStandaloneWithoutBaseIsRejected(): void
    {
        $this->requireZip();
        $path = $this->makePackage([
            'theme.json' => $this->manifest(),
            'public/css/theme.css' => 'body{}',
        ]);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('standalone theme must contain base.html.twig');
            $this->validator()->inspect($path);
        } finally { @unlink($path); }
    }

    public function testPageWrapperMustRenderContent(): void
    {
        $this->requireZip();
        $path = $this->makePackage([
            'theme.json' => $this->manifest(),
            'base.html.twig' => $this->baseTemplate(),
            'pages/catalogue.html.twig' => '<section>Catalogue</section>',
            'public/css/theme.css' => 'body{}',
        ]);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage("must extend '@theme/base.html.twig'");
            $this->validator()->inspect($path);
        } finally { @unlink($path); }
    }

    public function testExecutableServerFileIsRejected(): void
    {
        $this->requireZip();
        $path = $this->makePackage([
            'theme.json' => $this->manifest(),
            'base.html.twig' => $this->baseTemplate(),
            'public/css/theme.css' => 'body{}',
            'evil.php' => '<?php echo getenv("APP_KEY");',
        ]);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unsupported theme package file type');
            $this->validator()->inspect($path);
        } finally { @unlink($path); }
    }

    public function testOneFiveColourPaletteIsAccepted(): void
    {
        $this->requireZip();
        $manifest = json_decode($this->manifest(), true, 512, JSON_THROW_ON_ERROR);
        $manifest['palettes'] = [
            ['name'=>'Single','colors'=>['#2A324B','#767B91','#F7C59F','#C7CCDB','#E1E5EE']],
        ];
        $path = $this->makePackage([
            'theme.json' => json_encode($manifest, JSON_THROW_ON_ERROR),
            'base.html.twig' => $this->baseTemplate(),
            'public/css/theme.css' => 'body{}',
        ]);
        try {
            $result = $this->validator()->inspect($path);
            self::assertSame(1, $result['summary']['palettes']);
        } finally { @unlink($path); }
    }

    public function testMoreThanFivePalettesRemainValid(): void
    {
        $this->requireZip();
        $manifest = json_decode($this->manifest(), true, 512, JSON_THROW_ON_ERROR);
        $manifest['palettes'] = [];
        for ($i = 1; $i <= 6; $i++) {
            $manifest['palettes'][] = [
                'name' => 'Palette ' . $i,
                'colors' => ['#111111','#333333','#777777','#BBBBBB','#F5F5F5'],
            ];
        }
        $path = $this->makePackage([
            'theme.json' => json_encode($manifest, JSON_THROW_ON_ERROR),
            'base.html.twig' => $this->baseTemplate(),
            'public/css/theme.css' => 'body{}',
        ]);
        try {
            $result = $this->validator()->inspect($path);
            self::assertSame(6, $result['summary']['palettes']);
        } finally { @unlink($path); }
    }

    public function testPaletteEnabledThemeCannotShipCompetingSwitcher(): void
    {
        $this->requireZip();
        $manifest = json_decode($this->manifest(), true, 512, JSON_THROW_ON_ERROR);
        $manifest['palettes'] = [
            ['name'=>'One','colors'=>['#111111','#333333','#777777','#BBBBBB','#F5F5F5']],
            ['name'=>'Two','colors'=>['#101820','#34515E','#C47F38','#DDE5E8','#FFFFFF']],
        ];
        $path = $this->makePackage([
            'theme.json' => json_encode($manifest, JSON_THROW_ON_ERROR),
            'base.html.twig' => $this->baseTemplate() . '<div class="palette-switcher">Legacy picker</div>',
            'public/css/theme.css' => 'body{}',
        ]);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('core palette switcher exclusively');
            $this->validator()->inspect($path);
        } finally { @unlink($path); }
    }

    public function testAnotherFormatIsRefused(): void
    {
        $this->requireZip();
        $path = $this->makePackage([
            'theme.json' => $this->manifest(),
            'base.html.twig' => $this->baseTemplate(),
            'public/css/theme.css' => 'body{}',
        ]);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('theme.json format must be "an-application-theme".');
            (new PackageValidator('an-application-theme'))->inspect($path);
        } finally { @unlink($path); }
    }

    public function testAWrapperOfAFamilyTheProjectDoesNotNameIsNotChecked(): void
    {
        $this->requireZip();
        $path = $this->makePackage([
            'theme.json' => $this->manifest(),
            'base.html.twig' => $this->baseTemplate(),
            'pages/catalogue.html.twig' => '<section>Catalogue</section>',
            'public/css/theme.css' => 'body{}',
        ]);
        try {
            self::assertSame('Test Theme', (new PackageValidator())->inspect($path)['summary']['name']);
        } finally { @unlink($path); }
    }

    /** The project's format, with a page family it names (catalogue). */
    private function validator(): PackageValidator
    {
        return new PackageValidator('cattophp-theme', ['catalogue']);
    }

    /** @param array<string,string> $files */
    private function makePackage(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cattophp-theme-');
        if ($path === false) self::fail('Unable to create temporary file.');
        @unlink($path); $path .= '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($files as $name => $content) $zip->addFromString($name, $content);
        $zip->close();
        return $path;
    }

    private function manifest(): string
    {
        return json_encode([
            'format' => 'cattophp-theme',
            'schema_version' => '4.0',
            'template_api' => '2.0',
            'theme' => [
                'name' => 'Test Theme', 'slug' => 'test-theme', 'version' => '1.0.0',
                'author' => 'Test', 'created_at' => '2026-08-15T12:32:00+02:00',
                'description' => 'Validator test theme.',
            ],
            'parent' => null,
        ], JSON_THROW_ON_ERROR);
    }

    public function testStandaloneBaseMustLoadPlatformResources(): void
    {
        $this->requireZip();
        $path = $this->makePackage([
            'theme.json' => $this->manifest(),
            'base.html.twig' => '<html><body>{% block page_content %}{% block page_body %}{% endblock %}{% endblock %}</body></html>',
            'public/css/theme.css' => 'body{}',
        ]);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('platform.styles');
            $this->validator()->inspect($path);
        } finally { @unlink($path); }
    }

    private function baseTemplate(): string
    {
        return '<!doctype html><html><head>{% for url in platform.styles %}<link rel="stylesheet" href="{{ url }}">{% endfor %}</head><body>{% block page_content %}{% block page_body %}{% endblock %}{% endblock %}{% for url in platform.scripts %}<script src="{{ url }}"></script>{% endfor %}</body></html>';
    }

    private function requireZip(): void
    {
        if (!class_exists(ZipArchive::class)) self::markTestSkipped('The PHP zip extension is not installed.');
    }
}