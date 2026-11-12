<?php

declare(strict_types=1);

namespace CattoPHP\Document;

use RuntimeException;

/**
 * The data a caller handed the document renderer does not fit the document type's placeholders: a key
 * that is not registered, a required value that is missing, or a value of the wrong kind. It is a
 * programming error in the caller, reported loudly rather than rendered as a blank.
 */
final class DocumentDataException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The document data does not fit its template: ' . implode(' ', $errors));
    }
}
