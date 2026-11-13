<?php

declare(strict_types=1);

namespace CattoPHP\Http;

use InvalidArgumentException;

/**
 * A form refused for what is wrong with its fields, each with why in plain words, by the field's name: what a service
 * throws when it checks a whole form and finds one thing or several wrong, so the page can show each beside its field
 * at once. `TypedForm::keep($request, errors: $refused)` takes it as it is (its errors()), and the next page reads them
 * back by the field's name with `TypedForm::errors()` or `typed_errors_for()`, for each `form.field`'s `error`.
 *
 *     throw new FieldsRefused(['name' => 'Type the tool\'s name.', 'category' => 'Choose its category.']);
 *
 * Why under the name '' is about the form as a whole, which no field shows (TypedForm keeps only named fields). Its
 * message is every reason in one sentence, unless one is given. A project may extend it for a refusal of its own; being
 * an InvalidArgumentException, it is caught where a plain refusal is.
 */
class FieldsRefused extends InvalidArgumentException
{
    /** @var array<string,string> */
    private readonly array $errors;

    /**
     * @param array<string,string> $errors why each field was refused, by its name; '' for the form as a whole
     * @param string $message what to say of the whole refusal; every reason in one sentence when empty
     */
    public function __construct(array $errors, string $message = '')
    {
        $kept = [];
        foreach ($errors as $name => $why) {
            $why = trim($why);
            if ($why !== '') {
                $kept[(string) $name] = $why;
            }
        }
        $this->errors = $kept;
        parent::__construct($message !== '' ? $message : ($kept === [] ? 'The form was not accepted.' : implode(' ', array_values($kept))));
    }

    /** @return array<string,string> why each field was refused, by its name; '' for the form as a whole */
    public function errors(): array
    {
        return $this->errors;
    }

    /** Why one field was refused, or null when it was not. */
    public function error(string $name): ?string
    {
        return $this->errors[$name] ?? null;
    }
}
