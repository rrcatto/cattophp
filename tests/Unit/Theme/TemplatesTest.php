<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Theme;

use CattoPHP\Theme\Templates;
use PHPUnit\Framework\TestCase;
use Twig\Loader\FilesystemLoader;

/** `@theme`'s fall-through from a child to its parent, a family's wrapper, and a project's pages in their own namespace. */
final class TemplatesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cattophp-templates-' . bin2hex(random_bytes(6));
        foreach ([
            'parent-theme/base.html.twig' => 'parent-base',
            'parent-theme/pages/admin.html.twig' => "{% extends '@theme/base.html.twig' %}",
            'child-theme/partials/footer.html.twig' => 'child-footer',
        ] as $path => $contents) {
            if (!is_dir(dirname($this->root . '/' . $path))) mkdir(dirname($this->root . '/' . $path), 0775, true);
            file_put_contents($this->root . '/' . $path, $contents);
        }
    }

    protected function tearDown(): void
    {
        foreach (['parent-theme/pages/admin.html.twig', 'parent-theme/base.html.twig', 'child-theme/partials/footer.html.twig'] as $path) @unlink($this->root . '/' . $path);
        foreach (['parent-theme/pages', 'parent-theme', 'child-theme/partials', 'child-theme', ''] as $dir) @rmdir($this->root . '/' . $dir);
    }

    public function testAChildTemplateIsFoundBeforeItsParent(): void
    {
        $loader = new FilesystemLoader();
        (new Templates($loader))->use([$this->root . '/child-theme', $this->root . '/parent-theme']);

        // Shipped only by the child.
        self::assertTrue($loader->exists('@theme/partials/footer.html.twig'));
        // Shipped only by the parent, and reached by falling through.
        self::assertSame('parent-base', $loader->getSourceContext('@theme/base.html.twig')->getCode());
    }

    public function testAFamilyWithAWrapperUsesIt(): void
    {
        $templates = new Templates(new FilesystemLoader());
        $templates->use([$this->root . '/parent-theme']);

        self::assertSame('@theme/pages/admin.html.twig', $templates->layoutFor('admin'));
    }

    public function testAFamilyWithoutAWrapperFallsBackToTheBase(): void
    {
        $templates = new Templates(new FilesystemLoader());
        $templates->use([$this->root . '/parent-theme']);

        self::assertSame('@theme/base.html.twig', $templates->layoutFor('content'));
    }

    /** Choosing a theme replaces the namespace rather than adding to it, so a preview leaves nothing behind. */
    public function testSwitchingThemeDoesNotLeaveTheOldOneSearchable(): void
    {
        $loader = new FilesystemLoader();
        $templates = new Templates($loader);
        $templates->use([$this->root . '/parent-theme']);
        self::assertTrue($loader->exists('@theme/base.html.twig'));

        $templates->use([$this->root . '/child-theme']);
        self::assertFalse($loader->exists('@theme/base.html.twig'));
        self::assertTrue($loader->exists('@theme/partials/footer.html.twig'));
    }

    public function testAPageIsAddressedInItsOwnNamespace(): void
    {
        $loader = new FilesystemLoader();
        $templates = new Templates($loader);
        self::assertSame('@platform/pages/privacy.html.twig', $templates->pageTemplate('privacy'));
        self::assertFalse($templates->hasPage('privacy'));
        $loader->setPaths([$this->root . '/parent-theme'], Templates::PLATFORM_NAMESPACE);
        self::assertTrue($templates->hasPage('admin'));
    }
}
