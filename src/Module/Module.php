<?php

declare(strict_types=1);

namespace CattoPHP\Module;

use CattoPHP\Schema\SchemaStep;
use CattoPHP\Schema\SqlStep;

/**
 * One of CattoPHP's optional modules, as it declares itself: its name in the configuration (`cattophp.<key>`), what it is
 * for, whether it keeps records in the database, the modules it needs switched on beside it and the ones it works better
 * with, the packages it needs (a class each provides, and the package's name), and the schema steps that make its tables.
 */
final class Module
{
    /**
     * @param array<string,string> $needs the modules that must be switched on beside it => why
     * @param array<string,string> $usesIfOn the modules it works better with => what it does with them
     * @param array<class-string|string,string> $packages a class or interface each package provides => the package's name
     * @param list<class-string<SchemaStep>> $schemaSteps the steps that make its tables, oldest first
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $purpose,
        public readonly bool $database = false,
        public readonly array $needs = [],
        public readonly array $usesIfOn = [],
        public readonly array $packages = [],
        public readonly array $schemaSteps = [],
    ) {
    }

    /** @return list<string> the tables its schema steps leave in a database */
    public function tables(): array
    {
        return self::tablesOf($this->schemaSteps);
    }

    /**
     * The tables a list of schema steps leaves in a database, taken in order: each step's new tables added, and those a
     * later step drops taken away.
     *
     * @param list<class-string<SchemaStep>> $steps
     * @return list<string>
     */
    public static function tablesOf(array $steps): array
    {
        $tables = [];
        foreach ($steps as $step) {
            $made = new $step();
            if (!$made instanceof SqlStep) {
                continue;
            }
            $tables = array_values(array_diff([...$tables, ...$made->tables()], $made->dropped()));
        }

        return $tables;
    }
}
