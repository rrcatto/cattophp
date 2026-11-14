<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

/**
 * A template made ready for one page: the sections it gives (kind, width, shown when, shown, data checked against the
 * kind, the offers section with the page's offers) and, in words, the kinds left out because they do not belong on the
 * page or the page holds one already.
 */
final class TemplateApplied
{
    /**
     * @param list<array{kind:string,width:string,shown_when:string,enabled:bool,data:array<string,mixed>,reference?:string}> $sections
     * @param list<string> $skipped
     */
    public function __construct(public readonly array $sections, public readonly array $skipped = [])
    {
    }
}
