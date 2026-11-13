<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Schema\Steps\Step202611130100IdentityConnection;
use CattoPHP\Schema\Steps\Step202611130200Connections;
use CattoPHP\Settings\ConnectionStore;
use CattoPHP\Settings\DbalConnectionStore;
use CattoPHP\Settings\MemoryConnectionStore;
use CattoPHP\Settings\ServiceConnections;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;

/**
 * Where each connection to an outside service stands, in one table for every service (a
 * connection exists only once a real try established it): the last try's outcome for the details it was made with, so
 * details changed since are not checked yet; the database and memory stores alike; and Didit's row moved from the table of
 * its own into the shared one.
 */
final class ServiceConnectionsTest extends DatabaseTestCase
{
    public function testTheLastTryDecidesForTheDetailsItWasMadeWith(): void
    {
        foreach (['database' => new DbalConnectionStore($this->connection), 'memory' => new MemoryConnectionStore()] as $kind => $store) {
            $this->tryEveryState($store, $kind);
        }
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM cattophp_connections WHERE name = 'example'"), 'One row a connection.');
    }

    private function tryEveryState(ConnectionStore $store, string $kind): void
    {
        $connections = new ServiceConnections($store, (new Foundations())->keyedHash());
        self::assertSame('none', $connections->status('example', ['', ' '])['state'], $kind . ': no details, nothing to connect.');
        self::assertSame('unchecked', $connections->status('example', ['key-1', 'secret-1'])['state'], $kind . ': details never tried.');

        $connections->record('example', ['key-1', 'secret-1'], false, 'The provider did not accept the key (401).');
        $failed = $connections->status('example', ['key-1', 'secret-1']);
        self::assertSame(['failed', 'The provider did not accept the key (401).'], [$failed['state'], $failed['error']], $kind);
        self::assertNotSame('', $failed['checked_at']);
        self::assertFalse($connections->connected('example', ['key-1', 'secret-1']));

        $connections->record('example', ['key-1', 'secret-2'], true, learnt: ['workflow_label' => 'ID and selfie']);
        $connected = $connections->status('example', ['key-1', 'secret-2']);
        self::assertSame(['connected', '', ['workflow_label' => 'ID and selfie']], [$connected['state'], $connected['error'], $connected['learnt']], $kind);
        self::assertTrue($connections->connected('example', [' key-1 ', 'secret-2']), $kind . ': spaces around a detail change nothing.');
        self::assertSame(['unchecked', '', []], [($changed = $connections->status('example', ['key-1', 'secret-3']))['state'], $changed['checked_at'], $changed['learnt']], $kind . ': a detail changed since the try is not checked yet.');
        self::assertSame('unchecked', $connections->status('other', ['key-1', 'secret-2'])['state'], $kind . ': each connection by its own name.');

        $connections->record('example', ['key-1', 'secret-2'], false, str_repeat('x', 400));
        self::assertSame(ServiceConnections::LONGEST_ERROR, mb_strlen($connections->status('example', ['key-1', 'secret-2'])['error']), $kind . ': what the service said, kept short.');
        $connections->record('example', ['key-1', 'secret-2'], false);
        self::assertSame('The service did not say why.', $connections->status('example', ['key-1', 'secret-2'])['error'], $kind . ': a failure always says something.');

        $connections->forget('example');
        self::assertSame('unchecked', $connections->status('example', ['key-1', 'secret-2'])['state'], $kind . ': forgotten, not checked again until Check the connection.');
        $connections->record('example', ['key-1', 'secret-2'], true);
    }

    public function testDiditsRowMovesFromItsOwnTableIntoTheSharedOne(): void
    {
        // A database as step 202611130100 left it, with the connection to Didit made.
        $this->connection->executeStatement('DROP TABLE cattophp_connections');
        $this->connection->executeStatement("DELETE FROM cattophp_schema_steps WHERE id = '202611130200-connections'");
        (new Step202611130100IdentityConnection())->apply($this->connection);
        $this->connection->executeStatement("INSERT INTO cattophp_identity_connection (id, state) VALUES (1, '{\"checked_at\":\"2026-11-13T01:31:00+02:00\",\"connected\":true,\"error\":\"\",\"workflow_label\":\"ID and selfie\"}')");
        self::assertSame(['202611130200-connections'], array_map(static fn($step): string => $step->id(), $this->runner()->pending()));

        $this->runner()->migrate();

        self::assertNull($this->connection->fetchOne("SELECT to_regclass('cattophp_identity_connection')"), 'The table of its own is gone.');
        $didit = (new ServiceConnections(new DbalConnectionStore($this->connection), (new Foundations())->keyedHash()))->status('didit', ['sk_test_kept', 'wf-kept']);
        self::assertSame(['connected', '2026-11-13T01:31:00+02:00', ['workflow_label' => 'ID and selfie']], [$didit['state'], $didit['checked_at'], $didit['learnt']], 'Moved as didit, still connected.');
    }

    public function testTheStepNeedsNoOlderTableWherePaymentsAloneMakeIt(): void
    {
        $this->connection->executeStatement('DROP TABLE cattophp_connections');
        (new Step202611130200Connections())->apply($this->connection);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_connections'));
        self::assertSame(['cattophp_connections'], (new Step202611130200Connections())->tables());
        self::assertSame(['cattophp_identity_connection'], (new Step202611130200Connections())->dropped());
    }
}
