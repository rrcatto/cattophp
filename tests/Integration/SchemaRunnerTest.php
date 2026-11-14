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
        self::assertSame(['202611091000-settings', '202611091001-activity', '202611091002-access', '202611091003-sign-in', '202611091004-activity-lookups', '202611091005-access-boundary', '202611091006-links', '202611091007-sessions', '202611120900-scheduled-jobs', '202611121400-setup-steps', '202611121600-site-protection', '202611121800-telegram', '202611122000-search-pages', '202611122100-api-tokens', '202611122200-signup-attempts', '202611122201-identity-checks', '202611122300-inbox', '202611122310-cattomail', '202611122320-mailing-lists', '202611122330-mailing-campaigns', '202611122340-mailing-forwards', '202611130100-identity-connection', '202611130200-connections', '202611130300-scheduled-job-trials', '202611132100-marketing-campaigns', '202611140010-marketing-landing', '202611141200-marketing-sales', '202611141500-marketing-forgotten-customers'], $this->runner()->applied());
        self::assertSame([], $this->runner()->pending());
        self::assertSame([], $this->runner()->migrate(), 'a second run applies nothing');
        foreach (['cattophp_settings', 'cattophp_activity', 'cattophp_roles', 'cattophp_role_permissions', 'cattophp_account_roles', 'cattophp_links', 'cattophp_sessions', 'cattophp_two_step', 'cattophp_scheduled_jobs', 'cattophp_setup_steps', 'cattophp_site_requests', 'cattophp_site_flags', 'cattophp_site_lockouts', 'cattophp_site_refused_emails', 'cattophp_telegram_state', 'cattophp_telegram_messages', 'cattophp_telegram_accounts', 'cattophp_search_pages', 'cattophp_api_tokens', 'cattophp_connections'] as $table) {
            self::assertNotNull($this->connection->fetchOne('SELECT to_regclass(?)', [$table]), $table);
        }
        self::assertSame('Administrator', $this->connection->fetchOne("SELECT name FROM cattophp_roles WHERE key = 'ADMIN'"));
        self::assertNull($this->connection->fetchOne("SELECT to_regclass('cattophp_identity_connection')"), 'Its row moved into cattophp_connections.');
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
        self::assertStringContainsString('28 steps would be applied', $tester->getDisplay());
        self::assertStringContainsString('202611091002-access  Roles and permissions', $tester->getDisplay());
        self::assertSame([], $this->runner()->applied(), 'a dry run applies nothing');

        $tester->execute([]);
        self::assertStringContainsString('Done: 28 steps applied.', $tester->getDisplay());
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
