<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Module\Module;
use CattoPHP\Module\Modules;
use CattoPHP\Schema\FoundationSteps;
use CattoPHP\Schema\SchemaRunner;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\Foundations;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** Each module boots alone in a project, and a database is given only its tables and the foundations'. */
final class ModulesKernelTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $url = getenv('CATTOPHP_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::fail('Set CATTOPHP_TEST_DATABASE_URL to a PostgreSQL database kept for these tests (README, "Developing it").');
        }
        $this->connection = DriverManager::getConnection((new DsnParser())->parse($url));
        $this->connection->executeStatement('DROP SCHEMA IF EXISTS public CASCADE');
        $this->connection->executeStatement('CREATE SCHEMA public');
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    /** @return array<string,array{0:array<string,bool>,1:list<string>}> a project's modules, as the test kernel's switches, and the keys they are */
    public static function projects(): array
    {
        return [
            'no module' => [[], []],
            'the scheduler' => [['scheduler' => true], ['scheduler']],
            'the setup checklist' => [['setup' => true], ['setup']],
            'site protection' => [['scheduler' => true, 'protection' => true], ['scheduler', 'protection']],
            'Telegram' => [['telegram' => true], ['telegram']],
            'search engines' => [['search' => true], ['search']],
            'documents' => [['documents' => true], ['documents']],
            'an API' => [['api' => true], ['api']],
            'the sign-up check' => [['signup' => true], ['signup']],
            'identity verification' => [['identity' => true], ['identity']],
            'the Inbox' => [['inbox' => true], ['inbox']],
            'mailing' => [['mailing' => true], ['mailing']],
        ];
    }

    /**
     * @param array<string,bool> $switches
     * @param list<string> $on
     */
    #[DataProvider('projects')]
    public function testAModuleBootsAloneAndItsDatabaseHasOnlyItsTables(array $switches, array $on): void
    {
        $folder = Foundations::folder();
        $on2 = static fn(string $key): bool => $switches[$key] ?? false;
        $kernel = new TestKernel($folder, (string) getenv('CATTOPHP_TEST_DATABASE_URL'), scheduler: $on2('scheduler'), setup: $on2('setup'), protection: $on2('protection'), telegram: $on2('telegram'),
            search: $on2('search'), documents: $on2('documents'), api: $on2('api'), signup: $on2('signup'), identity: $on2('identity'), inbox: $on2('inbox'), mailing: $on2('mailing'));
        try {
            $kernel->boot();
            $application = new Application($kernel);
            $migrate = new CommandTester($application->find('cattophp:migrate'));
            self::assertSame(0, $migrate->execute([]));
            $expected = [SchemaRunner::TABLE, ...Module::tablesOf(FoundationSteps::foundationClasses())];
            foreach ($on as $key) $expected = [...$expected, ...(Modules::get($key)?->tables() ?? [])];
            $tables = array_map('strval', $this->connection->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'cattophp\\_%'"));
            sort($expected);
            sort($tables);
            self::assertSame($expected, $tables, 'The foundations\' tables and the modules\' switched on, no more.');
            $list = new CommandTester($application->find('cattophp:modules'));
            self::assertSame(0, $list->execute([]));
            $display = $list->getDisplay();
            foreach ($on as $key) self::assertMatchesRegularExpression('/cattophp\.' . $key . '\s+On/', $display);
            self::assertStringNotContainsString('(not made)', implode("\n", array_filter(explode("\n", $display), static fn(string $line): bool => str_contains($line, ' On '))), 'Every table of a module switched on is made.');
        } finally {
            $kernel->shutdown();
            Foundations::remove($folder);
            restore_exception_handler();
        }
    }

    public function testAModuleSwitchedOnWithoutWhatItNeedsStopsTheProjectWithEveryReason(): void
    {
        $folder = Foundations::folder();
        $kernel = new TestKernel($folder, null, protection: true, inbox: true);
        try {
            $kernel->boot();
            self::fail('A project without what its modules need started.');
        } catch (\InvalidArgumentException $refused) {
            self::assertStringContainsString("CattoPHP's modules cannot start:", $refused->getMessage());
            self::assertStringContainsString('Site protection (cattophp.protection) keeps its records in the database', $refused->getMessage());
            self::assertStringContainsString('Site protection (cattophp.protection) needs the scheduler switched on', $refused->getMessage());
            self::assertStringContainsString('The Inbox (cattophp.inbox) keeps its records in the database', $refused->getMessage());
        } finally {
            Foundations::remove($folder);
            restore_exception_handler();
        }
    }
}
