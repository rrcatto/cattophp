<?php

declare(strict_types=1);

namespace CattoPHP\Sessions;

use DateTimeImmutable;

/** Where sign-ins are kept: in the database, or in a file for an installation without one. */
interface SessionStore
{
    /** Keeps a new sign-in by its cookie's hash and returns it. */
    public function start(string $hash, string $publicId, string $accountId, ?string $email, ?string $requestedFrom, ?string $userAgent, DateTimeImmutable $at, DateTimeImmutable $expiresAt): SignInSession;

    /** The sign-in this cookie carries, while it lasts and has not been ended. */
    public function active(string $hash, DateTimeImmutable $now): ?SignInSession;

    /** Notes that it was seen now and makes it last until $expiresAt. */
    public function touch(string $id, DateTimeImmutable $now, DateTimeImmutable $expiresAt): void;

    /** @return list<SignInSession> an account's sign-ins still going, the newest first */
    public function activeFor(string $accountId, DateTimeImmutable $now): array;

    /** Ends one of an account's sign-ins by its public id: whether one was going. */
    public function end(string $accountId, string $publicId, DateTimeImmutable $now): bool;

    /** Ends every sign-in an account has going. Returns how many ended. */
    public function endAllFor(string $accountId, DateTimeImmutable $now): int;

    /** Ends the sign-in this cookie carries: signing out. */
    public function endByHash(string $hash, DateTimeImmutable $now): void;

    /** Removes the sign-ins that ran out or were ended before a moment. Returns how many went. */
    public function forgetOld(DateTimeImmutable $before): int;

    /** An account being deleted: every sign-in it has, going or ended, goes. Returns how many went. */
    public function forgetAccount(string $accountId): int;
}
