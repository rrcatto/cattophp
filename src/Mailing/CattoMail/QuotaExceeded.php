<?php

declare(strict_types=1);

namespace CattoPHP\Mailing\CattoMail;

use DateTimeImmutable;
use RuntimeException;

/**
 * catto-mail refused a request because the account's daily or monthly quota is used up (429 quota-exceeded). Unlike being
 * busy, asking again soon does not help: nothing is asked again before resetsAt, when catto-mail said.
 */
final class QuotaExceeded extends RuntimeException
{
    public function __construct(string $message, public readonly ?DateTimeImmutable $resetsAt)
    {
        parent::__construct($message);
    }
}
