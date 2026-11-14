<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

/** A part of a landing page the project draws itself, such as its purchase box: a template of its own and the data it reads, words and lists only. */
final class ProjectPart
{
    /** @param array<string,mixed> $data */
    public function __construct(public readonly string $template, public readonly array $data = [])
    {
    }
}
