<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel;

use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\Foundations;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** Every project has cattophp:icons:search, over the icon library with the project's own subjects. */
final class IconSearchKernelTest extends TestCase
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

    public function testTheCommandIsThereInEveryProject(): void
    {
        $kernel = new TestKernel($this->folder);
        $kernel->boot();
        try {
            $tester = new CommandTester((new Application($kernel))->find('cattophp:icons:search'));
            self::assertSame(Command::SUCCESS, $tester->execute(['words' => ['hammer']]));
            self::assertStringStartsWith('hammer', $tester->getDisplay());
        } finally {
            $kernel->shutdown();
        }
    }
}
