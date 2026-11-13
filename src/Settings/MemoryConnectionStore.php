<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

/** The connections' states held for one run only: for a project without a database, and for tests. */
final class MemoryConnectionStore implements ConnectionStore
{
    /** @var array<string,array<string,mixed>> */
    private array $states = [];

    public function get(string $name): array
    {
        return $this->states[$name] ?? [];
    }

    public function put(string $name, array $state): void
    {
        $this->states[$name] = $state;
    }

    public function remove(string $name): void
    {
        unset($this->states[$name]);
    }
}
