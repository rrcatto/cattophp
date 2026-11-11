<?php

declare(strict_types=1);

namespace CattoPHP\Theme;

/**
 * An optional index of the installed themes a project keeps beside the folders (a table, say), for stable numbers and
 * a check that it still matches. The folders are always what counts: the index is rebuilt from them.
 */
interface ThemeRegistry
{
    /**
     * Each indexed theme by its key, with at least its manifest's checksum (`manifest_sha256`) and its number (`id`).
     *
     * @return array<string,array<string,mixed>>
     */
    public function byInstallKey(): array;

    /**
     * Replaces the index with these themes, as ThemeManager discovers them.
     *
     * @param list<array<string,mixed>> $themes
     */
    public function rebuild(array $themes): void;
}
