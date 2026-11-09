<?php

declare(strict_types=1);

namespace CattoPHP\Activity;

/**
 * A signed-in user the activity log can name. A user class that does not implement it is recorded by its user
 * identifier alone.
 */
interface NamedAccount
{
    public function accountId(): string;

    public function accountName(): string;
}
