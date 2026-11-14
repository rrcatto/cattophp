<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

/**
 * A tracking link in its three forms, each a whole address ready to copy: the short link (/c/spring-sale/fb1), the
 * campaign's link with ?src= naming the publication, and the same with UTM tags for tools that read them. All three
 * record the same touch.
 */
final class TrackingLink
{
    public function __construct(
        public readonly string $short,
        public readonly string $source,
        public readonly string $utm,
    ) {
    }
}
