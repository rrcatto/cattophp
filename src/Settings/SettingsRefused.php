<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

use InvalidArgumentException;

/** Settings that could not be saved, each with why in plain words. Nothing of the group was saved. */
final class SettingsRefused extends InvalidArgumentException
{
    /** @param array<string,string> $errors setting name => why; '' for the whole group */
    public function __construct(private readonly array $errors)
    {
        parent::__construct($errors === [] ? 'The settings were not saved.' : implode(' ', array_values($errors)));
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
