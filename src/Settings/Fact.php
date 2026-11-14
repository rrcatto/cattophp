<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

/** One line of a group of facts on the Settings page: its label and its value, in words, shown and never changed there. */
final class Fact
{
    public function __construct(
        public readonly string $label,
        public readonly string $value,
    ) {
    }
}
