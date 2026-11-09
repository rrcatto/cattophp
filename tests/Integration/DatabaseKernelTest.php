<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Settings\Settings;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\RecordingTransport;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;

/** The application with its records in the database: the schema command, then a sign-in that leaves its records there. */
final class DatabaseKernelTest extends DatabaseTestCase
{
    public function testTheSchemaCommandThenASignInWithItsRecordsInTheDatabase(): void
    {
        $this->connection->executeStatement('DROP SCHEMA public CASCADE');
        $this->connection->executeStatement('CREATE SCHEMA public');
        $folder = Foundations::folder();
        $kernel = new TestKernel($folder, (string) getenv('CATTOPHP_TEST_DATABASE_URL'), ['AUTH_TWO_STEP' => 'CHOICE']);
        try {
            $kernel->boot();
            $command = new CommandTester((new Application($kernel))->find('cattophp:migrate'));
            $command->execute([]);
            self::assertStringContainsString('Done: 8 steps applied.', $command->getDisplay());

            $browser = $kernel->getContainer()->get('test.client');
            self::assertInstanceOf(KernelBrowser::class, $browser);
            $browser->disableReboot();
            $page = $browser->request('GET', '/sign-in');
            $browser->submit($page->filter('form')->form(['email' => 'owner@example.com']), [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox/144.0', 'REMOTE_ADDR' => '203.0.113.9']);
            $transport = $kernel->getContainer()->get(RecordingTransport::class);
            self::assertInstanceOf(RecordingTransport::class, $transport);
            self::assertSame(1, preg_match('#https://example\.com(/sign-in/link/[0-9a-f]{64})#', (string) $transport->last()->getTextBody(), $found));
            $page = $browser->request('GET', $found[1] ?? '');
            $browser->submit($page->filter('form')->form());
            $browser->followRedirect();
            self::assertStringContainsString('Signed in as owner@example.com', (string) $browser->getResponse()->getContent());

            self::assertSame(
                ['sign_in.link_sent', 'sign_in.signed_in'],
                $this->connection->fetchFirstColumn('SELECT action FROM cattophp_activity ORDER BY id'),
            );
            self::assertSame('203.0.113.9', $this->connection->fetchOne("SELECT ip_address FROM cattophp_activity WHERE action = 'sign_in.link_sent'"));
            self::assertNotNull($this->connection->fetchOne("SELECT used_at FROM cattophp_links WHERE purpose = 'sign_in'"));

            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(\Symfony\Component\DependencyInjection\ContainerInterface::class, $container);
            $settings = $container->get(Settings::class);
            self::assertInstanceOf(Settings::class, $settings);
            self::assertTrue($settings->canSave());
        } finally {
            $kernel->shutdown();
            Foundations::remove($folder);
            restore_exception_handler();
        }
    }
}
