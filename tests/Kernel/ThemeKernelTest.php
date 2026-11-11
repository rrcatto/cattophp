<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel;

use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\ThemeFolders;
use CattoPHP\Theme\PageRenderer;
use CattoPHP\Theme\Templates;
use CattoPHP\Theme\ThemeManager;
use PHPUnit\Framework\TestCase;

/** The theme system as the bundle wires it for a project with themes: its folders, its package format and its pages. */
final class ThemeKernelTest extends TestCase
{
    private string $folder;
    private ThemeFolders $site;

    protected function setUp(): void
    {
        $this->folder = Foundations::folder();
        $this->site = new ThemeFolders();
        mkdir($this->site->root . '/pages/pages', 0775, true);
        file_put_contents($this->site->root . '/pages/pages/welcome.html.twig', '{% extends layout %}{% block page_body %}<h1>Welcome to {{ site }}</h1>{% endblock %}');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
        Foundations::remove($this->folder);
    }

    public function testAProjectsPageIsDrawnInItsBundledDefaultTheme(): void
    {
        $kernel = new TestKernel($this->folder, null, [], true, [
            'bundled' => $this->site->root . '/bundled', 'installed' => $this->site->root . '/installed', 'staging' => $this->site->root . '/staging',
            'public_root' => $this->site->root . '/public', 'pages_folder' => $this->site->root . '/pages',
        ]);
        $kernel->boot();
        try {
            $container = $kernel->getContainer();
            $themes = $container->get(ThemeManager::class);
            self::assertInstanceOf(ThemeManager::class, $themes);
            self::assertSame('plain-v1.2.0', $themes->activeTheme());
            self::assertInstanceOf(Templates::class, $container->get(Templates::class));
            $themes->publish('plain-v1.2.0');

            $pages = $container->get(PageRenderer::class);
            self::assertInstanceOf(PageRenderer::class, $pages);
            $html = $pages->render('welcome');
            self::assertStringContainsString('<meta name="generator" content="Example 1.0.0">', $html);
            self::assertStringContainsString('<h1>Welcome to Example Site</h1>', $html);
            self::assertStringContainsString('<link rel="stylesheet" href="/site.css">', $html);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testAProjectWithoutThemesHasNoThemeSystem(): void
    {
        $kernel = new TestKernel($this->folder, null, [], true);
        $kernel->boot();
        try {
            self::assertFalse($kernel->getContainer()->has(ThemeManager::class));
            self::assertFalse($kernel->getContainer()->has(PageRenderer::class));
        } finally {
            $kernel->shutdown();
        }
    }
}
