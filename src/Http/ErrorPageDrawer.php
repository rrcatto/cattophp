<?php

declare(strict_types=1);

namespace CattoPHP\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Draws an error page in a project's own look: CattoPHP decides what it says (ErrorPage), the project how it looks. A
 * drawer that fails is answered with plain HTML, never the same drawer again.
 */
interface ErrorPageDrawer
{
    /** The page's HTML. */
    public function draw(ErrorPage $page, Request $request): string;
}
