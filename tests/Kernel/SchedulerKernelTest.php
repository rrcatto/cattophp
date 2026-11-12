<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel;

use CattoPHP\Scheduler\TaskStatus;
use CattoPHP\Settings\Settings;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\Foundations;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** A project switching the scheduler on: its own task found, the command under both names, the runs kept in its files. */
final class SchedulerKernelTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        $this->folder = Foundations::folder();
    }

    protected function tearDown(): void
    {
        Foundations::remove($this->folder);
        restore_exception_handler();
    }

    public function testTheProjectsTaskRunsUnderEitherNameAndItsRunIsKept(): void
    {
        $kernel = new TestKernel($this->folder, scheduler: true);
        $kernel->boot();
        try {
            $application = new Application($kernel);
            self::assertSame($application->find('cattophp:schedule:run'), $application->find('schedule:run'), 'The project\'s old command name is the same command.');
            $tester = new CommandTester($application->find('schedule:run'));
            self::assertSame(Command::SUCCESS, $tester->execute([]));
            self::assertSame("reminders: No reminders were due.\nlog_archive: The logs were empty, so nothing was archived. Deleted 0 archives older than 2 weeks.\n", $tester->getDisplay(), 'CattoPHP\'s own task beside the project\'s.');
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            $settings = $container->get(Settings::class);
            self::assertInstanceOf(Settings::class, $settings);
            self::assertSame('2w', $settings->get('LOG_ARCHIVES_KEPT_FOR'), 'Its setting is in the project\'s Settings.');
            self::assertFileExists($this->folder . '/storage/scheduled-jobs.json', 'A project without a database keeps its runs in its folder.');
            $status = $kernel->getContainer()->get(TaskStatus::class);
            self::assertInstanceOf(TaskStatus::class, $status);
            self::assertSame(['reminders', 'Reminders', false, 1], [$status->tasks()[0]['key'], $status->tasks()[0]['label'], $status->tasks()[0]['overdue'], $status->tasks()[0]['run_count']]);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testAProjectThatDoesNotSwitchItOnHasNoScheduler(): void
    {
        $kernel = new TestKernel($this->folder);
        $kernel->boot();
        try {
            self::assertFalse((new Application($kernel))->has('cattophp:schedule:run'));
            self::assertFalse($kernel->getContainer()->has(TaskStatus::class));
        } finally {
            $kernel->shutdown();
        }
    }
}
