<?php

declare(strict_types=1);

namespace CattoPHP\Theme;

use CattoPHP\Access\Permission;
use InvalidArgumentException;

/**
 * One page in a menu section: its label, where it goes (a route with its parameters, or an address: a path on the site or
 * a web address), the permission a person needs to see it (none: everyone who sees the menu) and an optional icon from
 * the project's sprite. Made with `MenuEntry::route()` or `MenuEntry::address()`.
 */
final class MenuEntry
{
    /**
     * @param array<string,string|int> $parameters
     */
    private function __construct(
        public readonly string $label,
        public readonly ?string $route,
        public readonly array $parameters,
        public readonly ?string $address,
        public readonly ?string $permission,
        public readonly string $icon,
    ) {
        if (trim($label) === '') {
            throw new InvalidArgumentException('A menu entry needs a label in words.');
        }
        if ($permission !== null && preg_match(Permission::PATTERN, $permission) !== 1) {
            throw new InvalidArgumentException(sprintf('Menu entry %s needs a permission named like AREA.THING.ACTION: "%s" is not.', $label, $permission));
        }
        if ($icon !== '' && preg_match(MenuSection::NAME, $icon) !== 1) {
            throw new InvalidArgumentException(sprintf('Menu entry %s names an icon as the sprite does, such as nav-settings: "%s" is not.', $label, $icon));
        }
    }

    /**
     * A page of the site by its route.
     *
     * @param array<string,string|int> $parameters The route's parameters.
     * @param string|null $permission The permission a person needs to see it, if any.
     */
    public static function route(string $label, string $route, array $parameters = [], ?string $permission = null, string $icon = ''): self
    {
        if (trim($route) === '') {
            throw new InvalidArgumentException('Menu entry ' . $label . ' needs a route name.');
        }

        return new self($label, $route, $parameters, null, $permission, $icon);
    }

    /**
     * A page by its address: a path on the site (/help) or a web address (https://example.com/), never a script.
     *
     * @param string|null $permission The permission a person needs to see it, if any.
     */
    public static function address(string $label, string $address, ?string $permission = null, string $icon = ''): self
    {
        if (preg_match('#^(/(?!/)|https?://[^\s/])[^\s]*$#i', $address) !== 1) {
            throw new InvalidArgumentException(sprintf('Menu entry %s goes to a path on the site or a web address: "%s" is neither.', $label, $address));
        }

        return new self($label, null, [], $address, $permission, $icon);
    }
}
