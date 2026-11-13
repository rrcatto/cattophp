<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel;

use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\ThemeFolders;
use CattoPHP\Theme\ThemeManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * CattoPHP's own pages in a project with themes, with none of them replaced: they wear the theme (its frame and the
 * project's stylesheets), with CattoPHP's page head, where they used to draw CattoPHP's plain frame unless the project
 * replaced every one.
 */
final class StockPagesThemeTest extends TestCase
{
    private string $folder;
    private ThemeFolders $site;
    private TestKernel $kernel;
    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->folder = Foundations::folder();
        $this->site = new ThemeFolders();
        mkdir($this->site->root . '/pages/pages', 0775, true);
        $this->kernel = new TestKernel($this->folder, null, [], false, [
            'bundled' => $this->site->root . '/bundled', 'installed' => $this->site->root . '/installed', 'staging' => $this->site->root . '/staging',
            'public_root' => $this->site->root . '/public', 'pages_folder' => $this->site->root . '/pages',
        ]);
        $this->kernel->boot();
        $themes = $this->kernel->getContainer()->get(ThemeManager::class);
        self::assertInstanceOf(ThemeManager::class, $themes);
        $themes->publish('plain-v1.2.0');
        $browser = $this->kernel->getContainer()->get('test.client');
        self::assertInstanceOf(KernelBrowser::class, $browser);
        $this->browser = $browser;
        $this->browser->disableReboot();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        $this->site->remove();
        Foundations::remove($this->folder);
        restore_exception_handler();
    }

    public function testTheSignInPagesWearTheThemeWithCattoPHPsPageHead(): void
    {
        $page = $this->browser->request('GET', '/sign-in');

        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $html = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('<link rel="stylesheet" href="/site.css">', $html, 'The project\'s stylesheets: the theme\'s frame.');
        self::assertStringContainsString('<meta name="generator" content="Example 1.0.0">', $html, 'Drawn by the page renderer.');
        self::assertSame('Sign in', trim($page->filter('.cl-page-head h1')->text()), 'CattoPHP\'s page head holds the page\'s only heading.');
        self::assertCount(1, $page->filter('h1'));
        self::assertCount(1, $page->filter('form input[name="email"]'));
        self::assertCount(0, $page->filter('.cl-stock-page'), 'Not the plain frame\'s page.');

        $this->browser->submit($page->filter('form')->form(['email' => 'stranger@example.org']), [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/144.0']);
        $sent = $this->browser->followRedirect();
        self::assertStringContainsString('/site.css', (string) $this->browser->getResponse()->getContent());
        self::assertSame('Check your email', trim($sent->filter('.cl-page-head h1')->text()));
    }

    public function testTheErrorPageWearsTheThemeToo(): void
    {
        $page = $this->browser->request('GET', '/open/broken');

        self::assertSame(500, $this->browser->getResponse()->getStatusCode());
        self::assertStringContainsString('<link rel="stylesheet" href="/site.css">', (string) $this->browser->getResponse()->getContent());
        self::assertCount(1, $page->filter('.cl-page-head h1'));
        self::assertStringContainsString('Reference', $page->filter('.cl-error-reference')->text());
        self::assertStringNotContainsString('10.0.0.5', (string) $this->browser->getResponse()->getContent(), 'Never the fault itself.');
    }
}
