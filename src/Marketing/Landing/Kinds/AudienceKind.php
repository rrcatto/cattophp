<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing\Kinds;

/** Who it is for: a heading, words and up to 12 short points, each a line or a bold title and its line. Once a page. */
final class AudienceKind extends ListKind
{
    public function key(): string
    {
        return 'audience';
    }

    public function label(): string
    {
        return 'Who it is for';
    }

    public function description(): string
    {
        return 'Short points about the people it suits.';
    }

    public function icon(): string
    {
        return 'users';
    }

    public function defaultHeading(): string
    {
        return 'Who it is for';
    }

    protected function most(): int
    {
        return 12;
    }

    protected function titles(): bool
    {
        return true;
    }

    protected function item(): string
    {
        return 'statement';
    }
}
