<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Activity\Actor;
use CattoPHP\Settings\Settings;
use CattoPHP\Setup\SetupChecklist;
use CattoPHP\Tests\Support\ExampleSetupSteps;
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
            self::assertStringContainsString('Done: 8 steps applied.', $command->getDisplay(), 'A project with no module is given the foundations\' tables alone.');

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

    /** A project's own schema steps are found by autoconfiguration and applied by cattophp:migrate with CattoPHP's, in order. */
    public function testTheProjectsOwnStepsAreAppliedWithCattoPHPs(): void
    {
        $this->connection->executeStatement('DROP SCHEMA public CASCADE');
        $this->connection->executeStatement('CREATE SCHEMA public');
        $folder = Foundations::folder();
        $kernel = new TestKernel($folder, (string) getenv('CATTOPHP_TEST_DATABASE_URL'), projectStep: true);
        try {
            $kernel->boot();
            $command = new CommandTester((new Application($kernel))->find('cattophp:migrate'));
            $command->execute(['--dry-run' => true]);
            self::assertStringContainsString('9 steps would be applied', $command->getDisplay());
            self::assertStringContainsString("202611131200-example-notes  The project's notes", $command->getDisplay(), 'Listed with CattoPHP\'s.');
            $command->execute([]);
            self::assertStringContainsString('Done: 9 steps applied.', $command->getDisplay());
            self::assertSame(['202611091000-settings', '202611131200-example-notes'], [$this->connection->fetchOne('SELECT id FROM cattophp_schema_steps ORDER BY id LIMIT 1'), $this->connection->fetchOne('SELECT id FROM cattophp_schema_steps ORDER BY id DESC LIMIT 1')], 'In the order of their ids.');
            $this->connection->executeStatement('INSERT INTO example_notes (words) VALUES (?)', ['The project\'s own table.']);
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM example_notes'));
        } finally {
            $kernel->shutdown();
            Foundations::remove($folder);
            restore_exception_handler();
        }
    }

    /** A project with a database and the scheduler on keeps its runs in cattophp_scheduled_jobs. */
    public function testTheSchedulersRunsAreKeptInTheDatabase(): void
    {
        $folder = Foundations::folder();
        $kernel = new TestKernel($folder, (string) getenv('CATTOPHP_TEST_DATABASE_URL'), scheduler: true);
        try {
            $kernel->boot();
            $command = new CommandTester((new Application($kernel))->find('schedule:run'));
            self::assertSame(0, $command->execute([]));
            self::assertSame(['log_archive', 'reminders'], $this->connection->fetchFirstColumn('SELECT job_key FROM cattophp_scheduled_jobs ORDER BY job_key'));
            self::assertSame(['No reminders were due.', 1], array_values($this->connection->fetchAssociative("SELECT last_summary, run_count FROM cattophp_scheduled_jobs WHERE job_key = 'reminders'") ?: []));
            self::assertFileDoesNotExist($folder . '/storage/scheduled-jobs.json');
        } finally {
            $kernel->shutdown();
            Foundations::remove($folder);
            restore_exception_handler();
        }
    }

    /** A project with a database and the setup checklist on: its own steps listed, a person's mark kept in cattophp_setup_steps. */
    public function testTheSetupChecklistListsTheProjectsStepsAndKeepsMarksInTheDatabase(): void
    {
        $folder = Foundations::folder();
        $kernel = new TestKernel($folder, (string) getenv('CATTOPHP_TEST_DATABASE_URL'), setup: true);
        try {
            $kernel->boot();
            $checklist = $kernel->getContainer()->get(SetupChecklist::class);
            self::assertInstanceOf(SetupChecklist::class, $checklist);
            self::assertSame(['details', 'mail', 'verification', 'theme'], array_column($checklist->steps(), 'key'));
            $checklist->markDone('mail', new Actor('7', 'Ada'));
            self::assertSame(['mail', 'done', '7'], array_values($this->connection->fetchAssociative('SELECT step_key, state, marked_by FROM cattophp_setup_steps') ?: []));
            self::assertSame('A test email was sent.', $checklist->steps()[1]['state']);
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM cattophp_activity WHERE action = 'setup.step_marked' AND subject_id = 'mail'"));
            $project = $kernel->getContainer()->get(ExampleSetupSteps::class);
            self::assertInstanceOf(ExampleSetupSteps::class, $project);
            $project->detailsFilled = true;
            self::assertSame(2, $checklist->progress()['done']);
        } finally {
            $kernel->shutdown();
            Foundations::remove($folder);
            restore_exception_handler();
        }
    }

    /** A public site with site protection: the gate before anything else, the settings in its catalogue, the rules as a scheduled task. */
    public function testSiteProtectionTurnsAwayALockedOutVisitorAndRunsItsRules(): void
    {
        $folder = Foundations::folder();
        $kernel = new TestKernel($folder, (string) getenv('CATTOPHP_TEST_DATABASE_URL'), scheduler: true, protection: true);
        try {
            $kernel->boot();
            $browser = new KernelBrowser($kernel);
            $browser->request('GET', '/sign-in');
            self::assertSame(200, $browser->getResponse()->getStatusCode());
            $this->connection->executeStatement("INSERT INTO cattophp_site_lockouts (kind, network, label, which, why) VALUES ('address', '127.0.0.1', '127.0.0.1', 'lockout', 'Testing')");
            $browser->request('GET', '/sign-in');
            self::assertSame(403, $browser->getResponse()->getStatusCode());
            self::assertSame(\CattoPHP\Protection\LockoutGate::PAGE, $browser->getResponse()->getContent());
            self::assertSame(['403', '/sign-in'], array_map('strval', array_values($this->connection->fetchAssociative('SELECT status, path FROM cattophp_site_requests WHERE refused') ?: [])));
            $settings = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(\Symfony\Component\DependencyInjection\ContainerInterface::class, $settings);
            $values = $settings->get(Settings::class);
            self::assertInstanceOf(Settings::class, $values);
            self::assertSame('on,20,10m,timeout,1h', $values->get('SITE_RULE_MISSING'));
            $command = new CommandTester((new Application($kernel))->find('schedule:run'));
            self::assertSame(0, $command->execute([]));
            self::assertSame("reminders: No reminders were due.\nlog_archive: The logs were empty, so nothing was archived. Deleted 0 archives older than 2 weeks.\nsite_protection: 0 flagged, 0 timed out, 0 locked out, 0 released at the firewall, 0 search engines checked.\n", $command->getDisplay(), 'the rules after the tasks before them');
        } finally {
            $kernel->shutdown();
            Foundations::remove($folder);
            restore_exception_handler();
        }
    }

    /** A project with a Telegram bot: its services, and the owner's messages as a scheduled task after the others. */
    public function testTheTelegramModuleRunsItsTaskAndOffersItsServices(): void
    {
        $folder = Foundations::folder();
        $kernel = new TestKernel($folder, (string) getenv('CATTOPHP_TEST_DATABASE_URL'), scheduler: true, protection: true, telegram: true);
        try {
            $kernel->boot();
            $setup = $kernel->getContainer()->get(\CattoPHP\Telegram\TelegramSetup::class);
            self::assertInstanceOf(\CattoPHP\Telegram\TelegramSetup::class, $setup);
            self::assertSame(['on_sale' => true], $setup->view()['choices']);
            $command = new CommandTester((new Application($kernel))->find('schedule:run'));
            self::assertSame(0, $command->execute([]));
            self::assertStringEndsWith("site_protection: 0 flagged, 0 timed out, 0 locked out, 0 released at the firewall, 0 search engines checked.\nowner_messages: Not connected to Telegram.\n", $command->getDisplay());
        } finally {
            $kernel->shutdown();
            Foundations::remove($folder);
            restore_exception_handler();
        }
    }

    /** A public site with search engines and AI: robots.txt, the sitemap from its sources in order, llms.txt, its public pages and their words. */
    public function testTheSearchModuleOffersItsFilesAndPages(): void
    {
        $folder = Foundations::folder();
        $kernel = new TestKernel($folder, (string) getenv('CATTOPHP_TEST_DATABASE_URL'), search: true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer();
            $robots = $container->get(\CattoPHP\Search\RobotsTxt::class);
            self::assertInstanceOf(\CattoPHP\Search\RobotsTxt::class, $robots);
            $document = $robots->document();
            self::assertStringContainsString("User-agent: *\n# Signed-in pages.\nDisallow: /account/\nDisallow: /admin/\nAllow: /\n", $document);
            self::assertStringEndsWith("Sitemap: https://example.com/sitemap.xml\n# A summary of this site for AI assistants, in the llms.txt format (llmstxt.org): https://example.com/llms.txt\n", $document);
            $sitemap = $container->get(\CattoPHP\Search\Sitemap::class);
            self::assertInstanceOf(\CattoPHP\Search\Sitemap::class, $sitemap);
            preg_match_all('#<loc>([^<]+)</loc>#', (string) $sitemap->document(), $locs);
            self::assertSame(['https://example.com/', 'https://example.com/guides', 'https://example.com/guides/soil'], $locs[1], 'The public pages first, by their priority.');
            $llms = $container->get(\CattoPHP\Search\LlmsTxt::class);
            self::assertInstanceOf(\CattoPHP\Search\LlmsTxt::class, $llms);
            self::assertSame("# Example Site\n\n> Example Site is a garden design site.\n\n## Guides\n\n- [Soil](https://example.com/guides/soil): What a garden grows in.\n", $llms->document());
            $overrides = $container->get(\CattoPHP\Search\PageOverrides::class);
            self::assertInstanceOf(\CattoPHP\Search\PageOverrides::class, $overrides);
            $overrides->savePages(['pages' => ['guides' => ['title' => 'Garden guides', 'description' => '']]], new \CattoPHP\Activity\Actor('1', 'Ada'));
            $head = $container->get(\CattoPHP\Search\PageHead::class);
            self::assertInstanceOf(\CattoPHP\Search\PageHead::class, $head);
            $meta = $head->complete('guides', [], '/guides', [], 'Guides');
            self::assertSame(['description' => 'Every guide on the site.', 'title' => 'Garden guides', 'canonical' => 'https://example.com/guides'], array_intersect_key($meta, ['description' => 1, 'title' => 1, 'canonical' => 1]));
            $search = $container->get(\CattoPHP\Search\SearchSettings::class);
            self::assertInstanceOf(\CattoPHP\Search\SearchSettings::class, $search);
            $search->save(['ai_crawlers' => 'none', 'google' => '', 'bing' => '']);
            self::assertStringContainsString("User-agent: GPTBot\nDisallow: /\n", $robots->document(), 'Saved in Settings, read by robots.txt.');
            self::assertInstanceOf(\CattoPHP\Search\SharePictures::class, $container->get(\CattoPHP\Search\SharePictures::class));
        } finally {
            $kernel->shutdown();
            Foundations::remove($folder);
            restore_exception_handler();
        }
    }

    /** A project where people register and prove who they are: the sign-up check, its lists task and identity verification. */
    public function testTheSignupCheckAndIdentityVerificationAreOffered(): void
    {
        $folder = Foundations::folder();
        $kernel = new TestKernel($folder, (string) getenv('CATTOPHP_TEST_DATABASE_URL'), scheduler: true, signup: true, identity: true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer();
            $checks = $container->get(\CattoPHP\Signup\SignupChecks::class);
            self::assertInstanceOf(\CattoPHP\Signup\SignupChecks::class, $checks);
            self::assertSame(40, $checks->secondsOpen($checks->stamp(time() - 40)), 'The stamp is signed with the application key.');
            $found = $checks->check(['first_name' => 'Ada', 'last_name' => 'Lovelace'], 'ada@example.com');
            $attempts = $container->get(\CattoPHP\Signup\SignupAttempts::class);
            self::assertInstanceOf(\CattoPHP\Signup\SignupAttempts::class, $attempts);
            self::assertSame('link_sent', ($attempts->find($found['id']) ?? [])['outcome']);
            $countries = $container->get(\CattoPHP\Signup\SignupCountries::class);
            self::assertInstanceOf(\CattoPHP\Signup\SignupCountries::class, $countries);
            $countries->save('ZA', 'RU', new Actor('1', 'Ada'));
            $testContainer = $container->get('test.service_container');
            self::assertInstanceOf(\Symfony\Component\DependencyInjection\ContainerInterface::class, $testContainer);
            $settings = $testContainer->get(Settings::class);
            self::assertInstanceOf(Settings::class, $settings);
            self::assertSame('RU', $settings->get('SIGNUP_BLOCKED_COUNTRIES'), 'The lists are settings.');
            $scheduler = $container->get(\CattoPHP\Scheduler\Scheduler::class);
            self::assertInstanceOf(\CattoPHP\Scheduler\Scheduler::class, $scheduler);
            self::assertSame('Sign-up check lists', $scheduler->task('signup_lists')?->label());
            self::assertInstanceOf(\CattoPHP\Network\NoNetworkLookup::class, $container->get(\CattoPHP\Network\NetworkLookup::class), 'Without a database, only private addresses are known.');
            $identity = $container->get(\CattoPHP\Identity\IdentityVerification::class);
            self::assertInstanceOf(\CattoPHP\Identity\IdentityVerification::class, $identity);
            self::assertSame(['none', false], [$identity->state('7')['status'], $identity->state('7')['available']], 'No keys in .env: not available.');
            $identitySettings = $container->get(\CattoPHP\Identity\IdentitySettings::class);
            self::assertInstanceOf(\CattoPHP\Identity\IdentitySettings::class, $identitySettings);
            self::assertSame('https://example.com/account/verification/return', $identitySettings->returnUrl());
        } finally {
            $kernel->shutdown();
            Foundations::remove($folder);
            restore_exception_handler();
        }
    }

    /** A project with a contact form: the Inbox keeps and judges a message, its settings are a group, and its mailbox task runs. */
    public function testTheInboxKeepsAndJudgesAMessageAndRunsItsMailboxTask(): void
    {
        $folder = Foundations::folder();
        $kernel = new TestKernel($folder, (string) getenv('CATTOPHP_TEST_DATABASE_URL'), scheduler: true, inbox: true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer();
            $inbox = $container->get(\CattoPHP\Inbox\Inbox::class);
            self::assertInstanceOf(\CattoPHP\Inbox\Inbox::class, $inbox);
            $kept = $inbox->keep(['reason' => 'other', 'sender_name' => 'Ada Lovelace', 'sender_email' => 'ada@example.com', 'message' => 'A question about the garden guides.']);
            self::assertSame('MSG-000001', $kept['reference']);
            $inbox->arrived($kept['id'], 'Ada Lovelace', 'ada@example.com', 'A question about the garden guides.', false, [\CattoPHP\Inbox\Inbox::STAMP_FIELD => $inbox->stamp()]);
            $counts = $inbox->counts();
            self::assertSame(1, $counts['new'] + $counts['spam'], 'Filed: sent from the command line at once, with no browser, the spam check holds it.');
            self::assertInstanceOf(\CattoPHP\Inbox\InboxReplies::class, $container->get(\CattoPHP\Inbox\InboxReplies::class));
            $command = new CommandTester((new Application($kernel))->find('schedule:run'));
            self::assertSame(0, $command->execute(['--job' => 'inbox_mailbox']));
            self::assertStringContainsString('inbox_mailbox: No mailbox is set in Inbox settings.', $command->getDisplay());
        } finally {
            $kernel->shutdown();
            Foundations::remove($folder);
            restore_exception_handler();
        }
    }

    /** A project with an API: its firewall asks for a token, its routes for a scope and a permission, and its errors are its JSON document. */
    public function testTheApiAsksForATokenAScopeAndAPermission(): void
    {
        $folder = Foundations::folder();
        $kernel = new TestKernel($folder, (string) getenv('CATTOPHP_TEST_DATABASE_URL'), api: true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer();
            $tokens = $container->get(\CattoPHP\Api\ApiTokens::class);
            self::assertInstanceOf(\CattoPHP\Api\ApiTokens::class, $tokens);
            $test = $container->get('test.service_container');
            self::assertInstanceOf(\Symfony\Component\DependencyInjection\ContainerInterface::class, $test);
            $roles = $test->get(\CattoPHP\Access\Roles::class);
            self::assertInstanceOf(\CattoPHP\Access\Roles::class, $roles);
            $roles->grant('7', 'ADMIN');
            $admin = $tokens->create('7', 'Admin', ['example:read'])['token'];
            $plain = $tokens->create('8', 'Plain', ['example:read'])['token'];
            $left = $tokens->create('9', 'Left', ['example:read'])['token'];
            $send = static function (string $path, ?string $authorization) use ($kernel): \Symfony\Component\HttpFoundation\Response {
                return $kernel->handle(\Symfony\Component\HttpFoundation\Request::create($path, 'GET', [], [], [], $authorization === null ? [] : ['HTTP_AUTHORIZATION' => $authorization]));
            };
            $none = $send('/api/me', null);
            self::assertSame(401, $none->getStatusCode());
            self::assertSame('Bearer', $none->headers->get('WWW-Authenticate'));
            self::assertSame('{"error":{"status":401,"code":"unauthenticated","message":"A Bearer API token is required."}}', $none->getContent());
            self::assertStringContainsString('no-store', (string) $none->headers->get('Cache-Control'));
            self::assertSame('{"error":{"status":401,"code":"unauthenticated","message":"The API token is invalid, expired or revoked."}}', $send('/api/me', 'Bearer ' . $left)->getContent(), 'An account that may no longer use the API.');
            $ok = $send('/api/me', 'Bearer ' . $admin);
            self::assertSame(200, $ok->getStatusCode());
            self::assertSame(['account' => '7', 'name' => 'person7@example.com', 'scopes' => ['example:read']], json_decode((string) $ok->getContent(), true));
            $forbidden = $send('/api/me', 'Bearer ' . $plain);
            self::assertSame('{"error":{"status":403,"code":"forbidden","message":"The authenticated account does not have the required permission."}}', $forbidden->getContent(), 'The scope, but not the permission.');
            self::assertSame('{"error":{"status":404,"code":"not_found","message":"There is no such thing."}}', $send('/api/missing', 'Bearer ' . $admin)->getContent());
            self::assertSame('{"error":{"status":422,"code":"invalid_request","message":"Enter a title."}}', $send('/api/invalid', 'Bearer ' . $admin)->getContent());
        } finally {
            $kernel->shutdown();
            Foundations::remove($folder);
            restore_exception_handler();
        }
    }
}
