<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

/**
 * A tracking link being opened, found before anything is recorded: its campaign, the publication it names (by its path,
 * or by ?src= or utm_content), the source it came by, and the address it forwards to, with the query values it does not
 * know carried through.
 */
final class LinkOpening
{
    /** @param array<string,?string> $utm the utm_* values it came with */
    public function __construct(
        public readonly Campaign $campaign,
        public readonly ?Publication $publication,
        public readonly string $source,
        public readonly array $utm,
        public readonly string $address,
    ) {
    }
}
