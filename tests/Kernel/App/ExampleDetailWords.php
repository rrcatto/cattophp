<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel\App;

use CattoPHP\Activity\DetailWords;

/** A project's own words for its actions' details, registered with nothing but autoconfiguration. */
final class ExampleDetailWords implements DetailWords
{
    public function label(string $action, string $key): ?string
    {
        return $action === 'example.note_written' && $key === 'due_at' ? 'Due back' : null;
    }

    public function value(string $action, string $key, mixed $value): ?string
    {
        return $action === 'example.note_written' && $key === 'condition' && $value === 'needs_repair' ? 'Needs a repair first' : null;
    }
}
