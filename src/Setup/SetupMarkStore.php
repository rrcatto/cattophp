<?php

declare(strict_types=1);

namespace CattoPHP\Setup;

/** Where the marks on setup steps are kept. */
interface SetupMarkStore
{
    /** @return array<string,SetupMark> by step */
    public function all(): array;

    public function save(string $key, SetupMark $mark): void;

    public function remove(string $key): void;
}
