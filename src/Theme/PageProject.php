<?php

declare(strict_types=1);

namespace CattoPHP\Theme;

/**
 * What a project tells PageRenderer about its pages: each page's family, who may preview a theme, the values every
 * template may read, the pieces drawn before a page, the project's own view model and the generator tag's words.
 * PageRenderer does the rest the same way for every project: the theme, the layout, the theme's part of the view model
 * and the page as it is served.
 */
interface PageProject
{
    /** The family a page belongs to; a theme may wrap a family with pages/<family>.html.twig. */
    public function family(string $page): string;

    /**
     * Whether the reader of this page may see it in another installed theme (?theme_preview=<key>).
     *
     * @param array<string,mixed> $data
     */
    public function mayPreview(array $data): bool;

    /**
     * The values every template may read, a whole page or one piece of one: a form token, the site's name, its address.
     *
     * @return array<string,mixed>
     */
    public function scalars(): array;

    /**
     * The page's data with the pieces drawn before the page itself (sections a theme may rearrange).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function prepare(string $page, string $family, array $data): array;

    /**
     * The page's view model, built over the theme's part PageRenderer gives (`theme`, `theme_name`, `theme_asset_url`,
     * `color_scheme`, `theme_preview_active`), which it keeps: everything a page and its theme read.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $view
     * @return array<string,mixed>
     */
    public function model(string $page, string $family, array $data, array $view): array;

    /** The layout a family's pages extend when it is the project's own rather than the theme's (a reader's frame), else null. */
    public function layout(string $family): ?string;

    /** What the generator tag says first in every page's head, such as "Example 1.2.0". */
    public function generator(): string;
}
