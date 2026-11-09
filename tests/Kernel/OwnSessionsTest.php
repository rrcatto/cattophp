<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel;

use CattoPHP\Http\FormToken;
use CattoPHP\Settings\Settings;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\Foundations;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** A project that runs its own PHP sessions, with Symfony's session off: CattoPHP loads, without form tokens. */
final class OwnSessionsTest extends TestCase
{
    private string $folder;
    private TestKernel $kernel;

    protected function setUp(): void
    {
        $this->folder = Foundations::folder();
        $this->kernel = new TestKernel($this->folder, null, [], true);
        $this->kernel->boot();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        Foundations::remove($this->folder);
        restore_exception_handler();
    }

    public function testItLoadsAndItsPagesAndHeadersWork(): void
    {
        $browser = $this->kernel->getContainer()->get('test.client');
        self::assertInstanceOf(KernelBrowser::class, $browser);
        $browser->request('GET', '/open/nowhere');

        self::assertSame(404, $browser->getResponse()->getStatusCode());
        self::assertStringContainsString("default-src 'none'", (string) $browser->getResponse()->headers->get('Content-Security-Policy'));
        $settings = $this->container()->get(Settings::class);
        self::assertInstanceOf(Settings::class, $settings);
        self::assertSame('Example Site', $settings->get('APP_NAME'));
    }

    public function testAFormTokenSaysWhatItNeeds(): void
    {
        $tokens = $this->container()->get(FormToken::class);
        self::assertInstanceOf(FormToken::class, $tokens);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('turn framework.session on');
        $tokens->value();
    }

    public function testAProjectsOwnTemplateFunctionOfTheSameNameWins(): void
    {
        $twig = $this->container()->get('twig');
        self::assertInstanceOf(\Twig\Environment::class, $twig);

        self::assertSame('the project&#039;s own', $twig->createTemplate('{{ csp_nonce() }}')->render());
    }

    public function testNoVariableAProjectsTemplatesUseIsTaken(): void
    {
        $twig = $this->container()->get('twig');
        self::assertInstanceOf(\Twig\Environment::class, $twig);

        self::assertSame('none none none', $twig->createTemplate("{{ site_mode|default('none') }} {{ installation|default('none') }} {{ cattophp_layout|default('none') }}")->render());
        self::assertSame('Example Site', $twig->createTemplate('{{ cattophp.installation.name }}')->render());
    }

    private function container(): ContainerInterface
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }
}
