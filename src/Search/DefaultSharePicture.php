<?php

declare(strict_types=1);

namespace CattoPHP\Search;

/** The picture a page without one of its own is shared with (a site picture), at 1200 by 630. */
interface DefaultSharePicture
{
    /** Its full address, versioned, or null when there is none. */
    public function url(string $base): ?string;

    /** What it shows, in words, for those who cannot see it. */
    public function alt(): string;

    /** Its type, such as image/jpeg. */
    public function type(): string;
}
