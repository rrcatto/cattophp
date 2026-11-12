<?php

declare(strict_types=1);

namespace CattoPHP\Api;

/** A project's accounts as the API sees them: whether one may use the API now, and what an API identity says of it. */
interface ApiAccounts
{
    /**
     * What the project's API reports of the account, its name (an email address, say) as 'name' among it, or null when it
     * may not use the API now (disabled, deleted, its address not verified).
     *
     * @return array<string,mixed>|null
     */
    public function find(string $accountId): ?array;
}
