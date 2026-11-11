<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel\App;

use CattoPHP\Theme\PageProject;

/** A small project's pages for the theme system's kernel test: every page is content, previews for nobody. */
final class ExamplePages implements PageProject
{
    public function family(string $page): string
    {
        return str_starts_with($page, 'admin-') ? 'admin' : 'content';
    }

    public function mayPreview(array $data): bool
    {
        return false;
    }

    public function scalars(): array
    {
        return ['site' => 'Example Site'];
    }

    public function prepare(string $page, string $family, array $data): array
    {
        return $data;
    }

    public function model(string $page, string $family, array $data, array $view): array
    {
        return $data + $this->scalars() + $view + ['platform' => ['styles' => ['/site.css'], 'scripts' => []]];
    }

    public function layout(string $family): ?string
    {
        return null;
    }

    public function generator(): string
    {
        return 'Example 1.0.0';
    }
}
