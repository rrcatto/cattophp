<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Schema\MigrateCommand;
use CattoPHP\Schema\SchemaRunner;
use CattoPHP\Schema\SqlStep;
use CattoPHP\Tests\Support\DatabaseTestCase;
use InvalidArgumentException;
use Doctrine\DBAL\Exception as DatabaseError;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

final class SchemaRunnerTest extends DatabaseTestCase
{
    public function testEveryStepIsAppliedOnceInTheOrderItWasWritten(): void
    {
        self::assertSame(['202611091000-settings', '202611091001-activity', '202611091002-access', '202611091003-sign-in'], $this->runner()->applied());
        self::assertSame([], $this->runner()->pending());
        self::assertSame([], $this->runner()->migrate(), 'a second run applies nothing');
        foreach (['cattophp_settings', 'cattophp_activity', 'cattophp_roles', 'cattophp_role_permissions', 'cattophp_account_roles', 'cattophp_sign_in_links', 'cattophp_two_step'] as $table) {
            self::assertNotNull($this->connection->fetchOne('SELECT to_regclass(?)', [$table]), $table);
        }
        self::assertSame('Administrator', $this->connection->fetchOne("SELECT name FROM cattophp_roles WHERE key = 'ADMIN'"));
    }

    public function testAStepThatFailsLeavesNothingBehind(): void
    {
        $failing = new class extends SqlStep {
            public function id(): string
            {
                return '202611091100-fails-half-way';
            }

            public function describe(): string
            {
                return 'Fails half way';
            }

            protected function statements(): array
            {
                return ['CREATE TABLE half_made (id int)', 'SELECT * FROM no_such_table'];
            }
        };
        $runner = new SchemaRunner($this->connection, [...$this->runner()->steps(), $failing], new MockClock());

        try {
            $runner->migrate();
            self::fail('The step must fail.');
        } catch (DatabaseError) {
        }
        self::assertNull($this->connection->fetchOne("SELECT to_regclass('half_made')"));
        self::assertSame(['202611091100-fails-half-way'], array_map(static fn ($step): string => $step->id(), $runner->pending()));
    }

    public function testTheCommandShowsWhatItWouldDoBeforeDoingIt(): void
    {
        $this->connection->executeStatement('DROP SCHEMA public CASCADE');
        $this->connection->executeStatement('CREATE SCHEMA public');
        $tester = new CommandTester(new MigrateCommand($this->runner()));

        $tester->execute(['--dry-run' => true]);
        self::assertStringContainsString('4 steps would be applied', $tester->getDisplay());
        self::assertStringContainsString('202611091002-access  Roles and permissions', $tester->getDisplay());
        self::assertSame([], $this->runner()->applied(), 'a dry run applies nothing');

        $tester->execute([]);
        self::assertStringContainsString('Done: 4 steps applied.', $tester->getDisplay());
        $tester->execute([]);
        self::assertStringContainsString('Nothing to apply', $tester->getDisplay());
    }

    public function testAStepsIdSaysWhenItWasWritten(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SchemaRunner($this->connection, [new class extends SqlStep {
            public function id(): string
            {
                return 'settings';
            }

            public function describe(): string
            {
                return '';
            }

            protected function statements(): array
            {
                return [];
            }
        }], new MockClock());
    }
}
