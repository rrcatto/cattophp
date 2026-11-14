<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

use InvalidArgumentException;

/**
 * A section's copy or settings refused, with why in plain words and the form field it is about (such as headline, or
 * items.2.answer), so the editor shows the reason beside the field. Anything else a kind refuses is an
 * InvalidArgumentException about the section as a whole.
 */
final class CopyRefused extends InvalidArgumentException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
