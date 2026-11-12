<?php

declare(strict_types=1);

namespace CattoPHP\Document;

use RuntimeException;

/**
 * Everything wrong with a document template, reported together and in words an administrator can act
 * on: the unknown placeholder, the unclosed block, the unsupported page size. It never carries a
 * parser trace.
 */
final class TemplateValidationException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(count($errors) === 1 ? $errors[0] : 'The template has ' . count($errors) . ' problems: ' . implode(' ', $errors));
    }
}
