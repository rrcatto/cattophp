<?php

declare(strict_types=1);

namespace CattoPHP\Schema\Steps;

use CattoPHP\Schema\SqlStep;

/**
 * The search engines module's records (Search\PageOverrides): an administrator's own title and description for each of
 * the site's public pages, a row only for a page that has either. Who changed it is their account id in text.
 */
final class Step202611122000SearchPages extends SqlStep
{
    public function id(): string
    {
        return '202611122000-search-pages';
    }

    public function describe(): string
    {
        return 'Search engines: each public page\'s own title and description';
    }

    protected function statements(): array
    {
        return [
            'CREATE TABLE cattophp_search_pages (
                page_key text PRIMARY KEY CHECK (page_key ~ \'^[a-z][a-z0-9-]{0,63}$\'),
                title text NOT NULL DEFAULT \'\',
                description text NOT NULL DEFAULT \'\',
                updated_at timestamptz NOT NULL DEFAULT now(),
                updated_by text NULL
            )',
        ];
    }
}
