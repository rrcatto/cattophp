<?php

declare(strict_types=1);

namespace CattoPHP\Sessions;

use DateTimeImmutable;

/**
 * One sign-in, as it is kept: the cookie that carries it only as a hash. Who it is (the account, and the address it
 * signed in with), where it came from (a fingerprint the project gives) and in which browser, when it began and was last
 * seen, when it runs out, and whether it was ended. Its public id names it on a page that lists a person's sign-ins.
 */
final class SignInSession
{
    public function __construct(
        public readonly string $id,
        public readonly string $publicId,
        public readonly string $accountId,
        public readonly ?string $email,
        public readonly ?string $requestedFrom,
        public readonly ?string $userAgent,
        public readonly DateTimeImmutable $startedAt,
        public readonly DateTimeImmutable $lastSeenAt,
        public readonly DateTimeImmutable $expiresAt,
        public readonly ?DateTimeImmutable $endedAt = null,
    ) {
    }

    public function isActive(DateTimeImmutable $now): bool
    {
        return $this->endedAt === null && $this->expiresAt > $now;
    }
}
