<?php

declare(strict_types=1);

namespace CattoPHP\Activity;

use DateTimeImmutable;

/** Where the activity log is kept. Entries are only ever added. */
interface ActivityStore
{
    public function add(ActivityEntry $entry): void;

    /**
     * The newest entries first, narrowed by what is given, from the $offset-th on (a page of the log: page 3 of 50 a page
     * is offset 100).
     *
     * @return list<ActivityEntry>
     */
    public function recent(int $limit = 50, ?string $action = null, ?string $subjectType = null, ?string $subjectId = null, ?string $actorId = null, int $offset = 0): array;

    /** How many entries there are, narrowed as recent() narrows them: for a page of the log to say how many pages there are. */
    public function count(?string $action = null, ?string $subjectType = null, ?string $subjectId = null, ?string $actorId = null): int;

    /**
     * Every action the log holds at least one entry of, in alphabetical order: what a page may filter it by.
     *
     * @return list<string>
     */
    public function actions(): array;

    /** Removes every entry from before a moment, for a log kept only so long; returns how many went. */
    public function removeBefore(DateTimeImmutable $before): int;

    /**
     * Stops naming an account that is being deleted, keeping what happened: where it did something, the entry is by
     * $name and nobody's id; where it was what an entry was about (subject account), the entry is about nobody now; and in
     * those entries a detail that is one of $words (its address, its name), whatever its case, becomes $name too.
     * Returns how many entries changed.
     *
     * @param list<string> $words
     */
    public function forgetAccount(string $accountId, string $name, array $words = []): int;
}
