<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing\Kinds;

/** Outcomes: what someone will be able to do, a heading, words and up to 20 lines. Once a page. */
final class OutcomesKind extends ListKind
{
    public function key(): string
    {
        return 'outcomes';
    }

    public function label(): string
    {
        return 'Outcomes';
    }

    public function description(): string
    {
        return 'What someone will be able to do afterwards.';
    }

    public function icon(): string
    {
        return 'list-checks';
    }

    public function defaultHeading(): string
    {
        return 'What you will be able to do';
    }

    protected function most(): int
    {
        return 20;
    }

    protected function titles(): bool
    {
        return false;
    }

    protected function item(): string
    {
        return 'outcome';
    }
}
