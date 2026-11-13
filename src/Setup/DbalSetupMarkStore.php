<?php

declare(strict_types=1);

namespace CattoPHP\Setup;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/** The marks in the table cattophp_setup_steps (an application's own marks move into it as they stand). */
final class DbalSetupMarkStore implements SetupMarkStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function all(): array
    {
        $marks = [];
        foreach ($this->connection->fetchAllAssociative('SELECT step_key, state, note, marked_at, marked_by FROM cattophp_setup_steps ORDER BY step_key') as $row) {
            $marks[(string) $row['step_key']] = new SetupMark(
                (string) $row['state'],
                new DateTimeImmutable((string) $row['marked_at']),
                $row['marked_by'] === null ? null : (string) $row['marked_by'],
                (string) $row['note'],
            );
        }
        return $marks;
    }

    public function save(string $key, SetupMark $mark): void
    {
        $this->connection->executeStatement(
            'INSERT INTO cattophp_setup_steps (step_key, state, note, marked_at, marked_by) VALUES (:step, :state, :note, :at, :by)
             ON CONFLICT (step_key) DO UPDATE SET state = EXCLUDED.state, note = EXCLUDED.note, marked_at = EXCLUDED.marked_at, marked_by = EXCLUDED.marked_by',
            ['step' => $key, 'state' => $mark->state, 'note' => $mark->note, 'at' => $mark->at->format(DATE_ATOM), 'by' => $mark->by]
        );
    }

    public function remove(string $key): void
    {
        $this->connection->executeStatement('DELETE FROM cattophp_setup_steps WHERE step_key = :step', ['step' => $key]);
    }

    public function forget(string $account): int
    {
        return (int) $this->connection->executeStatement('UPDATE cattophp_setup_steps SET marked_by = NULL WHERE marked_by = :account', ['account' => $account]);
    }
}
