<?php

declare(strict_types=1);

namespace CattoPHP\Schema;

use Doctrine\DBAL\Connection;

/** A schema step that is a list of SQL statements, run in order. */
abstract class SqlStep implements SchemaStep
{
    /** @return list<string> */
    abstract protected function statements(): array;

    public function apply(Connection $connection): void
    {
        foreach ($this->statements() as $statement) {
            $connection->executeStatement($statement);
        }
    }
}
