<?php

declare(strict_types=1);

namespace CattoPHP\Setup;

/** The marks in memory, for tests. */
final class MemorySetupMarkStore implements SetupMarkStore
{
    /** @var array<string,SetupMark> */
    private array $marks = [];

    public function all(): array
    {
        ksort($this->marks);
        return $this->marks;
    }

    public function save(string $key, SetupMark $mark): void
    {
        $this->marks[$key] = $mark;
    }

    public function remove(string $key): void
    {
        unset($this->marks[$key]);
    }
}
