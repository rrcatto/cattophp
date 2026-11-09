<?php

declare(strict_types=1);

namespace CattoPHP\Links;

use DateTimeImmutable;

/** The links held for one run: for tests. */
final class MemoryLinkStore implements LinkStore
{
    /** @var array<array-key,array{hash:string,link:Link}> by number (PHP keeps a number as a number) */
    private array $links = [];

    private int $next = 1;

    public function add(string $hash, string $purpose, ?string $email, ?string $accountId, array $context, ?string $requestedFrom, DateTimeImmutable $at, DateTimeImmutable $expiresAt): string
    {
        $id = (string) $this->next++;
        $this->links[$id] = ['hash' => $hash, 'link' => new Link($id, $hash, $purpose, $email, $accountId, $context, $requestedFrom, $at, $expiresAt)];

        return $id;
    }

    public function find(string $hash, string $purpose): ?Link
    {
        foreach ($this->links as $kept) {
            if ($kept['hash'] === $hash && $kept['link']->purpose === $purpose) {
                return $kept['link'];
            }
        }

        return null;
    }

    public function usable(string $hash, string $purpose, DateTimeImmutable $now, bool $lock = false): ?Link
    {
        $link = $this->find($hash, $purpose);

        return $link !== null && $link->isUsable($now) ? $link : null;
    }

    public function usableById(string $id, string $purpose, DateTimeImmutable $now, bool $lock = false): ?Link
    {
        $link = $this->links[$id]['link'] ?? null;

        return $link !== null && $link->purpose === $purpose && $link->isUsable($now) ? $link : null;
    }

    public function changeContext(string $id, array $context): void
    {
        if (isset($this->links[$id])) {
            $this->links[$id]['link'] = self::with($this->links[$id]['link'], context: $context);
        }
    }

    public function use(string $id, DateTimeImmutable $now): bool
    {
        $link = $this->links[$id]['link'] ?? null;
        if ($link === null || $link->usedAt !== null) {
            return false;
        }
        $this->links[$id]['link'] = self::with($link, usedAt: $now);

        return true;
    }

    public function finishOpen(string $email, string $purpose, DateTimeImmutable $now): void
    {
        foreach ($this->links as $id => $kept) {
            if ($kept['link']->email === $email && $kept['link']->purpose === $purpose && $kept['link']->usedAt === null) {
                $this->links[$id]['link'] = self::with($kept['link'], usedAt: $now);
            }
        }
    }

    public function forgetOpen(string $accountId, string $purpose): void
    {
        foreach ($this->links as $id => $kept) {
            if ($kept['link']->accountId === $accountId && $kept['link']->purpose === $purpose && $kept['link']->usedAt === null) {
                unset($this->links[$id]);
            }
        }
    }

    public function forget(string $hash): void
    {
        foreach ($this->links as $id => $kept) {
            if ($kept['hash'] === $hash && $kept['link']->usedAt === null) {
                unset($this->links[$id]);
            }
        }
    }

    public function countForEmail(string $email, DateTimeImmutable $since, array $except = []): int
    {
        return count(array_filter($this->links, static fn (array $kept): bool => $kept['link']->email === $email && $kept['link']->createdAt > $since && !in_array($kept['link']->purpose, $except, true)));
    }

    public function countFrom(string $requestedFrom, DateTimeImmutable $since, array $except = []): int
    {
        return count(array_filter($this->links, static fn (array $kept): bool => $kept['link']->requestedFrom === $requestedFrom && $kept['link']->createdAt > $since && !in_array($kept['link']->purpose, $except, true)));
    }

    public function forgetOld(DateTimeImmutable $before): int
    {
        $old = array_filter($this->links, static fn (array $kept): bool => $kept['link']->expiresAt < $before || ($kept['link']->usedAt !== null && $kept['link']->usedAt < $before));
        $this->links = array_diff_key($this->links, $old);

        return count($old);
    }

    /** @param array<string,mixed>|null $context */
    private static function with(Link $link, ?array $context = null, ?DateTimeImmutable $usedAt = null): Link
    {
        return new Link($link->id, $link->hash, $link->purpose, $link->email, $link->accountId, $context ?? $link->context, $link->requestedFrom, $link->createdAt, $link->expiresAt, $usedAt ?? $link->usedAt);
    }
}
