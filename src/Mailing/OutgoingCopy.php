<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

/**
 * One person's copy of a mailing, drawn and ready to leave: the project's own id for it (what the transport's answer is
 * keyed by), the reference it travels with (catto-mail reports outcomes by it), the address, the words and the address of
 * its unsubscribe link, and the list it comes from (the List-Id: one job per list at catto-mail).
 */
final class OutgoingCopy
{
    public function __construct(
        public readonly int|string $id,
        public readonly string $reference,
        public readonly string $to,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
        public readonly string $unsubscribeUrl,
        public readonly string $listId,
        public readonly string $listName,
    ) {
    }
}
