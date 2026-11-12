<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel\App;

use CattoPHP\Api\ApiAccounts;

/** A project's accounts for its API: account 9 has left and may not use it. */
final class ExampleApiAccounts implements ApiAccounts
{
    /** @return array<string,mixed>|null */
    public function find(string $accountId): ?array
    {
        return $accountId === '9' ? null : ['name' => 'person' . $accountId . '@example.com'];
    }
}
