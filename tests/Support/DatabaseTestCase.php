<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use CattoPHP\Schema\SchemaRunner;
use CattoPHP\Schema\Steps\Step202611091000Settings;
use CattoPHP\Schema\Steps\Step202611091001Activity;
use CattoPHP\Schema\Steps\Step202611091002Access;
use CattoPHP\Schema\Steps\Step202611091003SignIn;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * A test against a real PostgreSQL database, named by CATTOPHP_TEST_DATABASE_URL (such as
 * pdo-pgsql://user:password@127.0.0.1:5432/cattophp_test). Each test starts from an empty schema with CattoPHP's steps
 * applied. The database must be one kept for these tests alone: everything in its public schema is removed.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        $url = getenv('CATTOPHP_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::fail('Set CATTOPHP_TEST_DATABASE_URL to a PostgreSQL database kept for these tests (README, "Developing it").');
        }
        $this->connection = DriverManager::getConnection((new DsnParser())->parse($url));
        $this->connection->executeStatement('DROP SCHEMA IF EXISTS public CASCADE');
        $this->connection->executeStatement('CREATE SCHEMA public');
        $this->runner()->migrate();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    protected function runner(): SchemaRunner
    {
        return new SchemaRunner($this->connection, [
            new Step202611091003SignIn(), new Step202611091001Activity(), new Step202611091000Settings(), new Step202611091002Access(),
        ], new MockClock('2026-11-09 10:00:00'));
    }
}
