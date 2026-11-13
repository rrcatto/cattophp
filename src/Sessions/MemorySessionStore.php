<?php

declare(strict_types=1);

namespace CattoPHP\Sessions;

use DateTimeImmutable;

/** The sign-ins held for one run: for tests. */
final class MemorySessionStore implements SessionStore
{
    /** @var array<array-key,array{hash:string,session:SignInSession}> by number */
    private array $sessions = [];

    private int $next = 1;

    public function start(string $hash, string $publicId, string $accountId, ?string $email, ?string $requestedFrom, ?string $userAgent, DateTimeImmutable $at, DateTimeImmutable $expiresAt): SignInSession
    {
        $session = new SignInSession((string) $this->next++, $publicId, $accountId, $email, $requestedFrom, $userAgent, $at, $at, $expiresAt);
        $this->sessions[$session->id] = ['hash' => $hash, 'session' => $session];

        return $session;
    }

    public function active(string $hash, DateTimeImmutable $now): ?SignInSession
    {
        foreach ($this->sessions as $kept) {
            if ($kept['hash'] === $hash && $kept['session']->isActive($now)) {
                return $kept['session'];
            }
        }

        return null;
    }

    public function touch(string $id, DateTimeImmutable $now, DateTimeImmutable $expiresAt): void
    {
        if (isset($this->sessions[$id])) {
            $this->sessions[$id]['session'] = self::with($this->sessions[$id]['session'], lastSeenAt: $now, expiresAt: $expiresAt);
        }
    }

    public function activeFor(string $accountId, DateTimeImmutable $now): array
    {
        $found = array_values(array_map(static fn (array $kept): SignInSession => $kept['session'],
            array_filter($this->sessions, static fn (array $kept): bool => $kept['session']->accountId === $accountId && $kept['session']->isActive($now))));
        usort($found, static fn (SignInSession $a, SignInSession $b): int => [$b->startedAt, (int) $b->id] <=> [$a->startedAt, (int) $a->id]);

        return $found;
    }

    public function end(string $accountId, string $publicId, DateTimeImmutable $now): bool
    {
        foreach ($this->sessions as $id => $kept) {
            if ($kept['session']->accountId === $accountId && $kept['session']->publicId === $publicId && $kept['session']->endedAt === null) {
                $this->sessions[$id]['session'] = self::with($kept['session'], endedAt: $now);

                return true;
            }
        }

        return false;
    }

    public function endAllFor(string $accountId, DateTimeImmutable $now): int
    {
        $ended = 0;
        foreach ($this->sessions as $id => $kept) {
            if ($kept['session']->accountId === $accountId && $kept['session']->endedAt === null) {
                $this->sessions[$id]['session'] = self::with($kept['session'], endedAt: $now);
                ++$ended;
            }
        }

        return $ended;
    }

    public function endByHash(string $hash, DateTimeImmutable $now): void
    {
        foreach ($this->sessions as $id => $kept) {
            if ($kept['hash'] === $hash && $kept['session']->endedAt === null) {
                $this->sessions[$id]['session'] = self::with($kept['session'], endedAt: $now);
            }
        }
    }

    public function forgetAccount(string $accountId): int
    {
        $gone = array_filter($this->sessions, static fn (array $kept): bool => $kept['session']->accountId === $accountId);
        $this->sessions = array_diff_key($this->sessions, $gone);

        return count($gone);
    }

    public function forgetOld(DateTimeImmutable $before): int
    {
        $old = array_filter($this->sessions, static fn (array $kept): bool => $kept['session']->expiresAt < $before || ($kept['session']->endedAt !== null && $kept['session']->endedAt < $before));
        $this->sessions = array_diff_key($this->sessions, $old);

        return count($old);
    }

    private static function with(SignInSession $s, ?DateTimeImmutable $lastSeenAt = null, ?DateTimeImmutable $expiresAt = null, ?DateTimeImmutable $endedAt = null): SignInSession
    {
        return new SignInSession($s->id, $s->publicId, $s->accountId, $s->email, $s->requestedFrom, $s->userAgent, $s->startedAt, $lastSeenAt ?? $s->lastSeenAt, $expiresAt ?? $s->expiresAt, $endedAt ?? $s->endedAt);
    }
}
