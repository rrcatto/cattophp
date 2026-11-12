<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Setup\DbalSetupMarkStore;
use CattoPHP\Setup\MemorySetupMarkStore;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\SetupMarkStoreContract;
use DateTimeImmutable;

/** The marks on setup steps in the database, and an application's own marks read as that application wrote them. */
final class DbalSetupMarkStoreTest extends DatabaseTestCase
{
    public function testTheDatabaseKeepsMarksAsEveryStoreDoes(): void
    {
        SetupMarkStoreContract::holds(new DbalSetupMarkStore($this->connection), 'database');
        SetupMarkStoreContract::holds(new MemorySetupMarkStore(), 'memory');
    }

    /** cattolms's marks (app_options setup.done.<step>, the time and the account), moved in as they stand. */
    public function testAnApplicationsOwnMarksAreReadAsItWroteThem(): void
    {
        $this->connection->executeStatement("INSERT INTO cattophp_setup_steps (step_key, state, marked_at, marked_by) VALUES ('site-mode', 'done', '2026-10-27 06:12:40+00:00', '1')");
        $mark = (new DbalSetupMarkStore($this->connection))->all()['site-mode'];
        self::assertTrue($mark->isDone());
        self::assertEquals(new DateTimeImmutable('2026-10-27 08:12:40+02:00'), $mark->at);
        self::assertSame(['1', ''], [$mark->by, $mark->note]);
    }

    public function testTheTableRefusesWhatNoStoreWrites(): void
    {
        foreach ([['Theme', 'done'], ['theme', 'maybe']] as [$step, $state]) {
            try {
                $this->connection->executeStatement('INSERT INTO cattophp_setup_steps (step_key, state, marked_at) VALUES (:step, :state, now())', ['step' => $step, 'state' => $state]);
                self::fail($step . ' ' . $state . ' is refused.');
            } catch (\Doctrine\DBAL\Exception\DriverException) {
                self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM cattophp_setup_steps'));
            }
        }
    }
}
