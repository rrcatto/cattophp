<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel;

use CattoPHP\Setup\SetupChecklist;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\Foundations;
use PHPUnit\Framework\TestCase;

/** The setup checklist is switched on by a project, and keeps its marks in the database (DatabaseKernelTest shows it working). */
final class SetupKernelTest extends TestCase
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

    public function testAProjectWithoutADatabaseIsToldTheChecklistNeedsOne(): void
    {
        $kernel = new TestKernel($this->folder, setup: true);
        try {
            $kernel->boot();
            self::fail('The container is not built.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame("CattoPHP's modules cannot start:\n- The setup checklist (cattophp.setup) keeps its records in the database: set cattophp.storage.type to database.", $refused->getMessage());
        }
    }

    public function testAProjectThatDoesNotSwitchItOnHasNoChecklist(): void
    {
        $kernel = new TestKernel($this->folder);
        $kernel->boot();
        try {
            self::assertFalse($kernel->getContainer()->has(SetupChecklist::class));
        } finally {
            $kernel->shutdown();
        }
    }
}
