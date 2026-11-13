<?php

declare(strict_types=1);

namespace CattoPHP\Schema;

use Doctrine\DBAL\Connection;

/**
 * One step of the schema, applied once by `cattophp:migrate`: CattoPHP's own records, or a project's, whose own classes
 * implementing this interface are tagged (autoconfiguration does it) and applied with CattoPHP's. A step is never changed
 * after it has been released: a later change is a new step. Its id starts with the date and time it was written
 * (`202611090900-settings`), so the steps run in the order they were written, CattoPHP's and the project's together.
 *
 * Steps are written for PostgreSQL, where a change to the schema is part of the transaction the runner wraps each step
 * in: a step that fails leaves nothing behind.
 */
interface SchemaStep
{
    /** `YYYYMMDDHHMM-what-it-does`, unique. */
    public function id(): string;

    /** What it does, in a few plain words, for the list `cattophp:migrate --dry-run` shows. */
    public function describe(): string;

    public function apply(Connection $connection): void;
}
