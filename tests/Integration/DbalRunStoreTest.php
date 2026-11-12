<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Scheduler\DbalRunStore;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\RunStoreContract;
use DateTimeImmutable;

/** The scheduler's runs in the database, and an application's own runs read as that application wrote them. */
final class DbalRunStoreTest extends DatabaseTestCase
{
    public function testTheDatabaseKeepsRunsAsEveryStoreDoes(): void
    {
        RunStoreContract::holds(new DbalRunStore($this->connection), 'database');
    }

    /** cattolms's scheduled_jobs rows, moved in as they stand, are read as the scheduler writes its own. */
    public function testAnApplicationsOwnRunsAreReadAsItWroteThem(): void
    {
        $this->connection->executeStatement("INSERT INTO cattophp_scheduled_jobs (job_key, last_started_at, last_finished_at, last_succeeded_at, last_failed_at, last_summary, last_error, run_count)
            VALUES ('commerce', '2026-11-12 09:59:00+02', '2026-11-12 09:59:01+02', '2026-11-12 09:59:01+02', '2026-11-11 08:00:00+02', 'Orders, emails, access and wallet credit brought up to date.', 'Mail server down', 4210)");
        $record = (new DbalRunStore($this->connection))->all()['commerce'];
        self::assertSame(4210, $record['run_count']);
        self::assertEquals(new DateTimeImmutable('2026-11-12 09:59:01+02:00'), new DateTimeImmutable((string) $record['last_succeeded_at']));
        self::assertSame(['Orders, emails, access and wallet credit brought up to date.', 'Mail server down'], [$record['last_summary'], $record['last_error']]);
        $store = new DbalRunStore($this->connection);
        $store->started('commerce', new DateTimeImmutable('2026-11-12 10:00:00+02:00'));
        self::assertSame(4211, $store->all()['commerce']['run_count'], 'And carried on from them.');
    }
}
