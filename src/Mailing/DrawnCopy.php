<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

/** A person's copy as the project drew it: its subject, its HTML and plain text, its unsubscribe link and its list's name. */
final class DrawnCopy
{
    public function __construct(
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
        public readonly string $unsubscribeUrl,
        public readonly string $listName,
    ) {
    }
}
