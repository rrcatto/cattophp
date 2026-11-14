<?php

declare(strict_types=1);

namespace CattoPHP\Theme;

/**
 * Adds pages to a project's menu: a module's own pages (such as its ready-made ones), or the project's. Each is a service
 * tagged `cattophp.menu` (done automatically for a class implementing this); `Menus` gathers their sections for the
 * person signed in, in the tag's priority order (highest first), and a project's `PageProject` adds them to its navbar.
 */
interface MenuProvider
{
    /** @return iterable<MenuSection> */
    public function menuSections(): iterable;
}
