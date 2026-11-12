<?php

declare(strict_types=1);

namespace CattoPHP\Schema;

use Doctrine\DBAL\Connection;

/** A schema step that is a list of SQL statements, run in order. */
abstract class SqlStep implements SchemaStep
{
    /** @return list<string> */
    abstract protected function statements(): array;

    /** @return list<string> the tables its statements make */
    public function tables(): array
    {
        $tables = [];
        foreach ($this->statements() as $statement) {
            if (preg_match('/^\s*CREATE TABLE (?:IF NOT EXISTS )?([a-z_][a-z0-9_]*)/i', $statement, $match) === 1) {
                $tables[] = $match[1];
            }
        }

        return $tables;
    }

    /** @return list<string> the tables its statements drop */
    public function dropped(): array
    {
        $tables = [];
        foreach ($this->statements() as $statement) {
            if (preg_match('/^\s*DROP TABLE (?:IF EXISTS )?([a-z_][a-z0-9_]*)/i', $statement, $match) === 1) {
                $tables[] = $match[1];
            }
        }

        return $tables;
    }

    public function apply(Connection $connection): void
    {
        foreach ($this->statements() as $statement) {
            $connection->executeStatement($statement);
        }
    }
}
