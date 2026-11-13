<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel;

use CattoPHP\Access\PermissionCatalogue;
use CattoPHP\Activity\ActivityDetails;
use CattoPHP\Scheduler\Scheduler;
use CattoPHP\ServiceTags;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\EveryExtension;
use CattoPHP\Tests\Support\Foundations;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The extension points: each interface's service tag is in ServiceTags, never on the interface, so one class of a
 * project's may implement several of them (a DetailWords and a SubjectLinks for the same records). An interface constant
 * inherited from two interfaces is a fatal error as the class loads, which static analysis does not catch.
 */
final class ExtensionPointsKernelTest extends TestCase
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

    public function testNoExtensionInterfaceDeclaresAConstantAndEachTagIsNamedOnce(): void
    {
        self::assertCount(14, ServiceTags::EXTENSION_POINTS);
        foreach (ServiceTags::EXTENSION_POINTS as $interface => $tag) {
            $reflection = new ReflectionClass($interface);
            self::assertTrue($reflection->isInterface(), $interface);
            self::assertSame([], $reflection->getConstants(), $interface . ' declares a constant, which a class implementing it and another extension interface with the same constant could not load: put it in ServiceTags.');
            self::assertMatchesRegularExpression('/^cattophp\.[a-z_]+$/', $tag);
        }
        self::assertSame(array_values(ServiceTags::EXTENSION_POINTS), array_values(array_unique(ServiceTags::EXTENSION_POINTS)), 'Each tag finds one kind of extension.');
        $constants = (new ReflectionClass(ServiceTags::class))->getConstants();
        unset($constants['EXTENSION_POINTS']);
        self::assertEqualsCanonicalizing(array_values($constants), array_values(ServiceTags::EXTENSION_POINTS), 'Every tag is an extension point.');
    }

    public function testOneClassImplementingEveryExtensionInterfaceLoadsAndIsFoundUnderEveryTag(): void
    {
        // Loading it is the first proof: with a TAG on each interface, PHP refused it ("inherits both ... which is ambiguous").
        $every = new EveryExtension();
        foreach (array_keys(ServiceTags::EXTENSION_POINTS) as $interface) {
            self::assertInstanceOf($interface, $every);
        }

        // Every module whose extension point it is switched on; the database is named but never reached.
        $kernel = new TestKernel($this->folder, 'pdo-pgsql://nobody:nothing@127.0.0.1:1/nothing', scheduler: true, setup: true, search: true, mailing: true,
            stockPages: true, everyExtension: true);
        $kernel->boot();
        try {
            $container = $kernel->getContainer();
            self::assertEqualsCanonicalizing(array_values(ServiceTags::EXTENSION_POINTS), $container->getParameter('test.every_extension_tags'),
                'Autoconfiguration gave the one class the tag of every interface it implements.');

            // And each of those that read it finds it.
            $services = $container->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $services);
            $permissions = $services->get(PermissionCatalogue::class);
            self::assertInstanceOf(PermissionCatalogue::class, $permissions);
            self::assertTrue($permissions->has('EXAMPLE.EVERYTHING.VIEW'), 'Its permission is in the catalogue.');
            $scheduler = $services->get(Scheduler::class);
            self::assertInstanceOf(Scheduler::class, $scheduler);
            self::assertContains('every_extension', array_map(static fn ($task): string => $task->key(), $scheduler->tasks()), 'It is a scheduled task.');
            $details = $services->get(ActivityDetails::class);
            self::assertInstanceOf(ActivityDetails::class, $details);
            self::assertSame('Due at', $details->label('example.everything_done', 'due_at'), 'As detail words, its empty label leaves the log\'s own.');
        } finally {
            $kernel->shutdown();
        }
    }
}
