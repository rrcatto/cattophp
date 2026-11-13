<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

/**
 * Where the state of each connection to an outside service is kept, by the connection's name (`didit`, `stitch`): what
 * its last real try left. ServiceConnections reads and writes it.
 */
interface ConnectionStore
{
    /** @return array<string,mixed> what the last try left, or nothing */
    public function get(string $name): array;

    /** @param array<string,mixed> $state */
    public function put(string $name, array $state): void;

    public function remove(string $name): void;
}
