<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Http;

use CattoPHP\Http\ErrorPage;
use CattoPHP\Http\TwigErrorPageDrawer;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\ThemeFolders;
use CattoPHP\Theme\PageProject;
use CattoPHP\Theme\PageRenderer;
use CattoPHP\Theme\Templates;
use CattoPHP\Twig\UiExtension;
use CattoPHP\Ui\Components;
use CattoPHP\Ui\IconLibrary;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/** The error page when the theme cannot draw it: drawn in CattoPHP's plain frame, a project's own error template too. */
final class ErrorPageDrawerTest extends TestCase
{
    private ThemeFolders $site;
    private string $own;

    protected function setUp(): void
    {
        $this->site = new ThemeFolders();
        $this->own = $this->site->root . '/own';
        mkdir($this->own, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testWhenTheThemeFailsCattoPHPsErrorPageIsDrawnInThePlainFrame(): void
    {
        $html = $this->drawer()->draw(self::page(), Request::create('/broken'));

        self::assertStringContainsString('<title>Something went wrong · Example Site</title>', $html, 'The plain frame.');
        self::assertStringContainsString('<html lang="en-GB">', $html, 'The installation\'s language: none set, plain British English.');
        self::assertStringContainsString('<main class="cl-stock-page">', $html, 'CattoPHP\'s own page knows the plain frame: its own heading, no theme\'s page head.');
        self::assertStringContainsString('<h1>Something went wrong</h1>', $html);
        self::assertStringContainsString('Reference RQ-1234', $html);
    }

    public function testAProjectsOwnErrorTemplateWrittenForItsThemeIsDrawnInThePlainFrameToo(): void
    {
        // As Catto Guard's is: it extends layout and fills page_body, so it needs layout even when the theme failed.
        file_put_contents($this->own . '/error.html.twig', '{% extends layout %}{% block page_body %}<p class="own-error">{{ title }}: {{ reference }}</p>{% endblock %}');

        $html = $this->drawer()->draw(self::page(), Request::create('/broken'));

        self::assertStringContainsString('<p class="own-error">Something went wrong: RQ-1234</p>', $html);
        self::assertStringContainsString('<title>Something went wrong · Example Site</title>', $html);
    }

    public function testThePlainFrameSaysThePagesAreInTheInstallationsLanguage(): void
    {
        $drawer = new TwigErrorPageDrawer($this->twig(TwigErrorPageDrawer::PLAIN_FRAME, ['APP_LOCALE' => 'en_ZA']));

        $html = $drawer->draw(self::page(), Request::create('/broken'));

        self::assertStringContainsString('<html lang="en-ZA">', $html, 'APP_LOCALE en_ZA, as HTML names it.');
        self::assertStringNotContainsString('lang="en-GB"', $html);
    }

    public function testWithoutThemesThePagesExtendTheProjectsLayout(): void
    {
        file_put_contents($this->own . '/frame.html.twig', '<!doctype html><title>The project\'s own</title>{% block body %}{% endblock %}');
        $drawer = new TwigErrorPageDrawer($this->twig('frame.html.twig'));

        $html = $drawer->draw(self::page(), Request::create('/broken'));

        self::assertStringContainsString('<title>The project\'s own</title>', $html, 'cattophp.layout, as before: no layout is given.');
        self::assertStringContainsString('<main class="cl-stock-page">', $html);
        self::assertStringContainsString('Reference RQ-1234', $html);
    }

    private function drawer(): TwigErrorPageDrawer
    {
        $twig = $this->twig(TwigErrorPageDrawer::PLAIN_FRAME);
        $loader = $twig->getLoader();
        self::assertInstanceOf(FilesystemLoader::class, $loader);
        $project = $this->createStub(PageProject::class);
        $project->method('family')->willReturn('content');
        $project->method('prepare')->willThrowException(new RuntimeException('The theme is broken.'));
        $pages = new PageRenderer($twig, new Templates($loader), $this->site->manager(), $project);

        return new TwigErrorPageDrawer($twig, $pages);
    }

    /** @param array<string,string> $values the installation's .env */
    private function twig(string $layout, array $values = []): Environment
    {
        $site = new Foundations(['APP_SITE_MODE' => 'live'] + $values);
        $loader = new FilesystemLoader();
        $loader->setPaths([$this->own, dirname(__DIR__, 3) . '/templates'], 'CattoPHP');
        $loader->addPath($this->own);
        $twig = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'html']);
        $twig->addExtension(new UiExtension(new Components(), new IconLibrary()));
        $twig->addGlobal('cattophp', ['layout' => $layout, 'installation' => $site->installation, 'site_mode' => $site->siteMode]);

        return $twig;
    }

    private static function page(): ErrorPage
    {
        return new ErrorPage('server', 'Something went wrong', 500, 'We could not finish that. Try again in a moment.', 'RQ-1234', '');
    }
}
