<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

/**
 * Where a mail server delivers an address, for a project that invents addresses for its test data (at a domain that
 * cannot resolve) and has them delivered to a mailbox of its own instead. Without one, every address is its own.
 */
interface RecipientAddresses
{
    public function deliveryAddress(string $email): string;
}
