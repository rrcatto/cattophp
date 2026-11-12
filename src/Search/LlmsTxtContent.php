<?php

declare(strict_types=1);

namespace CattoPHP\Search;

/**
 * What a project's /llms.txt says: its name, a short description, a few lines on how it works and its sections of
 * links. LlmsTxt writes them in the llms.txt format.
 */
interface LlmsTxtContent
{
    /** The heading: the site's name. */
    public function name(): string;

    /** The description quoted under the heading. */
    public function summary(): string;

    /**
     * Paragraphs after it, each on one line.
     *
     * @return list<string>
     */
    public function notes(): array;

    /**
     * The sections, in order, each with its links (absolute, from $base) and a line about each; a section without links
     * is left out.
     *
     * @return list<array{title:string,entries:list<array{name:string,url:string,notes:string}>}>
     */
    public function sections(string $base): array;
}
