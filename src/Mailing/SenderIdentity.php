<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

/** Who a mailing comes from: the address, the name, and the reply-to address ('' for none: replies go to the sender). */
final class SenderIdentity
{
    public function __construct(public readonly string $email, public readonly string $name, public readonly string $replyTo = '')
    {
    }
}
