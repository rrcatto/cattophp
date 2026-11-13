<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

use CattoPHP\Http\FieldsRefused;

/**
 * Settings that could not be saved, each with why in plain words. Nothing of the group was saved. A refusal of several
 * fields (Http\FieldsRefused), so `TypedForm::keep(..., errors: $refused)` takes it as it is.
 */
final class SettingsRefused extends FieldsRefused
{
    /** @param array<string,string> $errors setting name => why; '' for the whole group */
    public function __construct(array $errors)
    {
        parent::__construct($errors, array_filter($errors, static fn (string $why): bool => trim($why) !== '') === [] ? 'The settings were not saved.' : '');
    }
}
