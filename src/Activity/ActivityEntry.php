<?php

declare(strict_types=1);

namespace CattoPHP\Activity;

use DateTimeImmutable;

/** One line of the activity log. */
final class ActivityEntry
{
    /** @param array<string,mixed> $details */
    public function __construct(
        public readonly DateTimeImmutable $at,
        public readonly string $action,
        public readonly ?string $actorId,
        public readonly ?string $actorName,
        public readonly ?string $subjectType,
        public readonly ?string $subjectId,
        public readonly array $details,
        public readonly ?string $ipAddress,
        public readonly ?string $requestReference,
    ) {
    }
}
