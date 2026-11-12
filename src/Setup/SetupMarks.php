<?php

declare(strict_types=1);

namespace CattoPHP\Setup;

/** What people have marked, step by step, as a provider reads it. */
final class SetupMarks
{
    /** @param array<string,SetupMark> $marks */
    public function __construct(private readonly array $marks)
    {
    }

    public function get(string $key): ?SetupMark
    {
        return $this->marks[$key] ?? null;
    }

    public function isDone(string $key): bool
    {
        return ($this->marks[$key] ?? null)?->isDone() === true;
    }

    /** @return array<string,SetupMark> */
    public function all(): array
    {
        return $this->marks;
    }
}
