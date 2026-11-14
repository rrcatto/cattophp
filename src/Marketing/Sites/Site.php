<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sites;

use DateTimeImmutable;

/**
 * Another site this one is connected to, so that one campaign can run across both: its key (a slug made from its name,
 * how the records name it), its name, its https:// base address, the shared secret as stored (encrypted by
 * Security\SecretCipher, never shown) and the previous one while a new one is being put in on both sites, when this site
 * last sent to it, heard from it and checked the connection, how far apart the two clocks were at that check, the last
 * problem sending to it and the last arrival or report from it that was refused (each in words, with its moment), who
 * added it and when, and when it was removed (a removed site stays, so the touches and sales that name it keep its name).
 */
final class Site
{
    /** The subject of a connected site's entries in the activity log, its id being the site's key. */
    public const SUBJECT = 'marketing_site';

    /** A site's key: small letters, digits and hyphens, up to 40. */
    public const KEY = '/^[a-z0-9][a-z0-9-]{0,39}$/';

    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $address,
        #[\SensitiveParameter] public readonly string $secret,
        #[\SensitiveParameter] public readonly ?string $previousSecret = null,
        public readonly ?DateTimeImmutable $lastSentAt = null,
        public readonly ?DateTimeImmutable $lastReceivedAt = null,
        public readonly ?DateTimeImmutable $lastCheckedAt = null,
        public readonly ?int $clockApartSeconds = null,
        public readonly ?string $lastProblem = null,
        public readonly ?DateTimeImmutable $lastProblemAt = null,
        public readonly ?string $lastRefused = null,
        public readonly ?DateTimeImmutable $lastRefusedAt = null,
        public readonly ?string $createdBy = null,
        public readonly ?DateTimeImmutable $createdAt = null,
        public readonly ?DateTimeImmutable $removedAt = null,
    ) {
        if (preg_match(self::KEY, $key) !== 1) {
            throw new \InvalidArgumentException(sprintf('A connected site\'s key is small letters, digits and hyphens, such as example-org: "%s" is not.', $key));
        }
        if (preg_match('#^https://[^/\s]+(/\S*)?$#', $address) !== 1) {
            throw new \InvalidArgumentException(sprintf('A connected site\'s address starts with https:// and holds no spaces: "%s" does not.', $address));
        }
    }

    /** The host of its address, small letters, such as example.org: how a destination on that site is recognised. */
    public function host(): string
    {
        return strtolower((string) parse_url($this->address, PHP_URL_HOST));
    }

    /** Its address with no slash at the end, ready for a path: https://example.org. */
    public function base(): string
    {
        return rtrim($this->address, '/');
    }

    public function isRemoved(): bool
    {
        return $this->removedAt !== null;
    }

    /** Whether a new secret is being put in on both sites, the previous one still accepted beside it. */
    public function isChangingSecret(): bool
    {
        return $this->previousSecret !== null && $this->previousSecret !== '';
    }

    /** Whether its connection was checked and nothing has gone wrong since: the card says Connected. */
    public function isConnected(): bool
    {
        return $this->lastCheckedAt !== null && !$this->isRemoved() && ($this->lastProblemAt === null || $this->lastProblemAt < $this->lastCheckedAt);
    }
}
