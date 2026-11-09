<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel\App;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** A project's own template function with a name CattoPHP also uses. */
final class ProjectExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('csp_nonce', static fn (): string => 'the project\'s own')];
    }
}
