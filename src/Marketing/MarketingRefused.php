<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use CattoPHP\Http\FieldsRefused;

/**
 * A change to the Campaign Marketing Manager's records that was refused, with why in plain words by the form's field
 * (or '' for the form as a whole). Nothing was changed. A page keeps the form as typed with TypedForm::keep() and shows
 * each reason beside its field.
 */
final class MarketingRefused extends FieldsRefused
{
    /** A refusal about the whole form or action, which no field shows. */
    public static function because(string $why): self
    {
        return new self(['' => $why]);
    }
}
