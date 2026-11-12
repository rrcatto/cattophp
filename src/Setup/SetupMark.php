<?php

declare(strict_types=1);

namespace CattoPHP\Setup;

use DateTimeImmutable;
use InvalidArgumentException;

/** A person's mark on a step that needs them: done, or skipped for now, when, by whom, with an optional note. */
final class SetupMark
{
    public const DONE = 'done';
    public const SKIPPED = 'skipped';
    public const LONGEST_NOTE = 1000;

    public readonly string $note;

    /** @param string|null $by the person's account id, or null for the system */
    public function __construct(
        public readonly string $state,
        public readonly DateTimeImmutable $at,
        public readonly ?string $by = null,
        string $note = '',
    ) {
        if (!in_array($state, [self::DONE, self::SKIPPED], true)) {
            throw new InvalidArgumentException(sprintf('A setup step is marked done or skipped, not "%s".', $state));
        }
        $this->note = mb_substr(trim($note), 0, self::LONGEST_NOTE);
    }

    public function isDone(): bool
    {
        return $this->state === self::DONE;
    }
}
