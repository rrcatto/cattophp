<?php

declare(strict_types=1);

namespace CattoPHP\Schema;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Applies the schema steps that have not been applied yet, in the order of their ids, each in its own transaction, and
 * records each one when it is done. Two runs at the same moment cannot both apply a step: the run holds a lock in the
 * database until it ends. The steps are CattoPHP's (the foundations' and those of the modules switched on) and the
 * project's own: a class of the project's that implements SchemaStep is tagged by autoconfiguration (or by hand with
 * ServiceTags::SCHEMA_STEP) and applied by `cattophp:migrate` with CattoPHP's, all in the order of their ids. A project
 * may keep its own tables in it this way, or leave them to another tool (Phinx, Doctrine Migrations or anything else),
 * which then never touches CattoPHP's.
 */
final class SchemaRunner
{
    public const TABLE = 'cattophp_schema_steps';

    /** The lock's number, the same for every run against one database. */
    private const LOCK = 7_211_404_190;

    /** @var list<SchemaStep> */
    private readonly array $steps;

    /** @param iterable<SchemaStep> $steps */
    public function __construct(private readonly Connection $connection, iterable $steps, private readonly ClockInterface $clock)
    {
        $sorted = [];
        foreach ($steps as $step) {
            if (preg_match('/^\d{12}-[a-z0-9]+(-[a-z0-9]+)*$/', $step->id()) !== 1) {
                throw new InvalidArgumentException(sprintf('A schema step\'s id must be YYYYMMDDHHMM-what-it-does: "%s" is not.', $step->id()));
            }
            if (isset($sorted[$step->id()])) {
                throw new InvalidArgumentException(sprintf('Two schema steps have the id "%s".', $step->id()));
            }
            $sorted[$step->id()] = $step;
        }
        ksort($sorted, SORT_STRING);
        $this->steps = array_values($sorted);
    }

    /** @return list<SchemaStep> every step, in order */
    public function steps(): array
    {
        return $this->steps;
    }

    /** @return list<SchemaStep> the steps not yet applied, in order */
    public function pending(): array
    {
        $applied = array_flip($this->applied());

        return array_values(array_filter($this->steps, static fn (SchemaStep $step): bool => !isset($applied[$step->id()])));
    }

    /** @return list<string> the ids of the applied steps, in order */
    public function applied(): array
    {
        if (!$this->hasRecordTable()) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn('SELECT id FROM ' . self::TABLE . ' ORDER BY id');

        return $ids;
    }

    /**
     * Applies every pending step. $applying hears of each step before it runs.
     *
     * @param (callable(SchemaStep): void)|null $applying
     * @return list<string> the ids applied by this run
     */
    public function migrate(?callable $applying = null): array
    {
        $this->connection->executeStatement('SELECT pg_advisory_lock(' . self::LOCK . ')');
        try {
            $this->connection->executeStatement(
                'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (id text PRIMARY KEY, description text NOT NULL, applied_at timestamptz NOT NULL)'
            );
            $done = [];
            // Read again under the lock: another run may have applied some while this one waited.
            foreach ($this->pending() as $step) {
                if ($applying !== null) {
                    $applying($step);
                }
                $this->connection->transactional(function (Connection $connection) use ($step): void {
                    $step->apply($connection);
                    $connection->insert(self::TABLE, [
                        'id' => $step->id(),
                        'description' => $step->describe(),
                        'applied_at' => $this->clock->now()->format('Y-m-d H:i:sP'),
                    ]);
                });
                $done[] = $step->id();
            }

            return $done;
        } finally {
            $this->connection->executeStatement('SELECT pg_advisory_unlock(' . self::LOCK . ')');
        }
    }

    private function hasRecordTable(): bool
    {
        return $this->connection->fetchOne('SELECT to_regclass(?)', [self::TABLE]) !== null;
    }
}
