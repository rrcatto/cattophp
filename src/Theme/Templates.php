<?php

declare(strict_types=1);

namespace CattoPHP\Theme;

use Twig\Loader\FilesystemLoader;

/**
 * Points Twig's `@theme` namespace at the theme a request is drawn in. It resolves paths and template names; it draws
 * nothing and knows no page data.
 *
 * Two namespaces, and the split between them is the oldest rule of a themed page: `@platform` is the project's page
 * bodies, which belong to the project and never move into a theme; `@theme` is the frame around them, and it is a list of
 * folders, a child release first and then its parent, so a child that ships only a footer inherits every other template
 * by falling through. Twig's filesystem loader tries a namespace's folders in order, which is exactly that rule, so
 * nothing here implements inheritance; the order comes from ThemeManager::themeTemplateRoots().
 *
 * The namespace is set for each request rather than once, because an administrator may preview a theme. Twig's compiled
 * templates are kept by the file they came from, so two themes that both have base.html.twig cannot collide there.
 */
final class Templates
{
    public const THEME_NAMESPACE = 'theme';
    public const PLATFORM_NAMESPACE = 'platform';

    public function __construct(private readonly FilesystemLoader $loader)
    {
    }

    /**
     * Points `@theme` at a theme's folders, most specific first. setPaths() replaces rather than appends, which matters:
     * a preview must not leave the previous theme searchable behind the new one.
     *
     * @param list<string> $roots From ThemeManager::themeTemplateRoots(): child release, then parent.
     */
    public function use(array $roots): void
    {
        $this->loader->setPaths($roots, self::THEME_NAMESPACE);
    }

    /**
     * The layout a page of this family extends. A theme may ship an optional wrapper for a family (an administration
     * frame, a reader's frame), which extends the theme's base itself; without one, the page extends the base directly.
     * Deciding it here means a page template never has to know which themes have wrappers.
     */
    public function layoutFor(string $family): string
    {
        $wrapper = '@' . self::THEME_NAMESPACE . '/pages/' . $family . '.html.twig';

        return $this->loader->exists($wrapper) ? $wrapper : '@' . self::THEME_NAMESPACE . '/base.html.twig';
    }

    public function pageTemplate(string $page): string
    {
        return '@' . self::PLATFORM_NAMESPACE . '/pages/' . $page . '.html.twig';
    }

    public function hasPage(string $page): bool
    {
        return $this->loader->exists($this->pageTemplate($page));
    }
}
