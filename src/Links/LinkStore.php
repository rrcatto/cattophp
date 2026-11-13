<?php

declare(strict_types=1);

namespace CattoPHP\Links;

use DateTimeImmutable;

/** Where links that work once are kept: in the database, or in a file for an installation without one. */
interface LinkStore
{
    /**
     * Keeps a new link by its hash. Returns its number.
     *
     * @param array<string,mixed> $context
     */
    public function add(string $hash, string $purpose, ?string $email, ?string $accountId, array $context, ?string $requestedFrom, DateTimeImmutable $at, DateTimeImmutable $expiresAt): string;

    /** The link with this hash and purpose, used or not, run out or not. */
    public function find(string $hash, string $purpose): ?Link;

    /** The link while it can be used; with $lock it is held for the caller's database transaction (a file store holds none). */
    public function usable(string $hash, string $purpose, DateTimeImmutable $now, bool $lock = false): ?Link;

    /** The same, by its number. */
    public function usableById(string $id, string $purpose, DateTimeImmutable $now, bool $lock = false): ?Link;

    /** @param array<string,mixed> $context what the link carries now */
    public function changeContext(string $id, array $context): void;

    /** Uses the link up: whether it was still usable, so of two attempts at the same moment only one gets it. */
    public function use(string $id, DateTimeImmutable $now): bool;

    /** Uses up every open link of a purpose for an address: a new request replaces them, and they still count. */
    public function finishOpen(string $email, string $purpose, DateTimeImmutable $now): void;

    /** Removes an account's open links of a purpose. */
    public function forgetOpen(string $accountId, string $purpose): void;

    /** Removes an open link by its hash: one whose email could not be sent. */
    public function forget(string $hash): void;

    /**
     * How many links were asked for for an address since a moment, leaving out some purposes.
     *
     * @param list<string> $except
     */
    public function countForEmail(string $email, DateTimeImmutable $since, array $except = []): int;

    /**
     * How many links were asked for from one place since a moment, leaving out some purposes.
     *
     * @param list<string> $except
     */
    public function countFrom(string $requestedFrom, DateTimeImmutable $since, array $except = []): int;

    /** Removes the links that ran out or were used before a moment. Returns how many went. */
    public function forgetOld(DateTimeImmutable $before): int;

    /**
     * An account being deleted: every link of its own goes, used or not, and every link made for its address before it
     * had an account (a joining link holds the address alone). Returns how many went.
     */
    public function forgetAccount(string $accountId, ?string $email): int;
}
