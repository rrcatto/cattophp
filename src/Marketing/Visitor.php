<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use DateTimeImmutable;

/**
 * A visitor who came by a tracking link: the random token their browser keeps (nothing else is in the cookie), when they
 * were first and last seen, their account once known, their country and device, when their history was made anonymous
 * (their token and account gone, the totals kept), and their reference: their address on the Visitors pages and their
 * name until they are known ("Visitor 7k2m9q4d"), never the token.
 */
final class Visitor
{
    public const DEVICES = ['desktop' => 'Computer', 'phone' => 'Phone', 'tablet' => 'Tablet', 'unknown' => 'Unknown'];

    public function __construct(
        public readonly int $id,
        public readonly ?string $token,
        public readonly DateTimeImmutable $firstSeenAt,
        public readonly DateTimeImmutable $lastSeenAt,
        public readonly ?string $account,
        public readonly string $country,
        public readonly string $device,
        public readonly ?DateTimeImmutable $anonymisedAt,
        public readonly string $reference = '',
    ) {
    }

    /** What a visitor is called until their account is known: "Visitor 7k2m9q4d". */
    public function name(): string
    {
        return 'Visitor ' . $this->reference;
    }

    public function isAnonymous(): bool
    {
        return $this->anonymisedAt !== null;
    }
}
