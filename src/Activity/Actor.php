<?php

declare(strict_types=1);

namespace CattoPHP\Activity;

/** Who did something: a signed-in account, or the system itself (a scheduled job, a console command). */
final class Actor
{
    public function __construct(public readonly ?string $id, public readonly string $name)
    {
    }

    public static function system(): self
    {
        return new self(null, 'The system');
    }

    public function isSystem(): bool
    {
        return $this->id === null;
    }
}
