<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

use RuntimeException;

/**
 * The transport refused the copies, for a reason asking again will not mend (catto-mail's sending domain not verified, a
 * mail server setting that cannot be used, a request catto-mail turned down): what sends them pauses with the reason
 * until it is put right. The HTTP status and catto-mail's problem type are kept when there was an answer.
 */
final class TransportRefused extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly string $problem = '')
    {
        parent::__construct($message);
    }
}
