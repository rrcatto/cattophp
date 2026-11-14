<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

/** A landing page made or started again from a template, and the kinds of the template left out, in words, for the editor to say. */
final class TemplateResult
{
    /** @param list<string> $skipped */
    public function __construct(public readonly LandingPage $page, public readonly array $skipped = [])
    {
    }
}
