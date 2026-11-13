<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel;

use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\Foundations;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\HttpFoundation\Request;

/** A project's PHP takes the installation's time zone as every request and every console command starts. */
final class TimeZoneKernelTest extends TestCase
{
    private string $folder;

    private string $zone;

    protected function setUp(): void
    {
        $this->folder = Foundations::folder();
        $this->zone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
        Foundations::remove($this->folder);
        restore_exception_handler();
    }

    public function testEveryRequestAndCommandStartsInTheInstallationsTimeZone(): void
    {
        $kernel = new TestKernel($this->folder, starting: ['APP_TIMEZONE' => 'Pacific/Auckland'], scheduler: true);
        $kernel->boot();
        try {
            date_default_timezone_set('UTC');
            $kernel->handle(Request::create('/'));
            self::assertSame('Pacific/Auckland', date_default_timezone_get(), 'A request.');

            date_default_timezone_set('UTC');
            $application = new Application($kernel);
            $application->setAutoExit(false);
            $tester = new ApplicationTester($application);
            self::assertSame(0, $tester->run(['command' => 'cattophp:schedule:run', '--job' => ['reminders']]));
            self::assertSame('Pacific/Auckland', date_default_timezone_get(), 'A console command.');
        } finally {
            $kernel->shutdown();
        }
    }
}
