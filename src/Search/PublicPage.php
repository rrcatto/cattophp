<?php

declare(strict_types=1);

namespace CattoPHP\Search;

use InvalidArgumentException;

/**
 * One of a site's public pages that is not one of its own things (a home page, a help page, a legal page): its key, its
 * name in Settings, its address, the description search engines are given when neither the page nor an administrator
 * gives one, and the title the page gives itself.
 */
final class PublicPage
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $path,
        public readonly string $description,
        public readonly string $title,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $key) !== 1) {
            throw new InvalidArgumentException('A public page\'s key is lower-case letters, digits and hyphens.');
        }
        if (!str_starts_with($path, '/')) {
            throw new InvalidArgumentException('A public page\'s address is a path on the site, starting with /.');
        }
    }
}
