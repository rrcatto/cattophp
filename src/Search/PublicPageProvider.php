<?php

declare(strict_types=1);

namespace CattoPHP\Search;

/** A project's public pages (PublicPage), each with its own description and the words an administrator may give it. */
interface PublicPageProvider
{
    /** @return iterable<PublicPage> */
    public function pages(): iterable;
}
