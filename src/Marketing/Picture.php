<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

/** A stored picture (an advert's, a landing page's) read back, for a page that shows it to someone allowed to see it: its file, type and size. */
final class Picture
{
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly string $mimeType,
        public readonly int $size,
    ) {
    }
}
