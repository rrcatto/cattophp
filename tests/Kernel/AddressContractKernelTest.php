<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel;

use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Ui\Contract\AddressContract;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouterInterface;

/** CattoPHP's own pages keep the rule they give projects: no row number in an address (a role by its key, a link by its token). */
final class AddressContractKernelTest extends TestCase
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

    public function testCattoPHPsOwnRoutesTakeNoRowNumber(): void
    {
        $kernel = new TestKernel($this->folder, stockPages: true);
        $kernel->boot();
        try {
            $router = $kernel->getContainer()->get('router');
            self::assertInstanceOf(RouterInterface::class, $router);
            $routes = $router->getRouteCollection();
            self::assertNotNull($routes->get('cattophp_admin_role'), 'Administration\'s pages are among the routes checked.');
            self::assertNotNull($routes->get('cattophp_sign_in_link'), 'The sign-in pages are among them.');
            self::assertSame([], AddressContract::rowNumbers($routes));
        } finally {
            $kernel->shutdown();
        }
    }
}
