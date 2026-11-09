<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel\App;

use CattoPHP\CattoPHPBundle;
use CattoPHP\Installation\Installation;
use CattoPHP\Mail\Mailer;
use CattoPHP\Runtime\SiteMode;
use CattoPHP\Settings\Settings;
use CattoPHP\SignIn\SignInAuthenticator;
use CattoPHP\SignIn\SignInEntryPoint;
use CattoPHP\SignIn\SignInUserProvider;
use CattoPHP\Tests\Support\RecordingLogger;
use CattoPHP\Tests\Support\RecordingTransport;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * A small application with CattoPHP in it, as a project would have it: its records in files (as a page without a
 * database keeps them) or in the database, the sign-in pages, and three pages of its own.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /** @param array<string,string> $starting starting values in place of .env's */
    public function __construct(private readonly string $folder, private readonly ?string $databaseUrl = null, private readonly array $starting = [])
    {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new SecurityBundle(), new TwigBundle(), new CattoPHPBundle()];
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return $this->folder . '/cache/' . md5(serialize([$this->databaseUrl, $this->starting]));
    }

    public function getLogDir(): string
    {
        return $this->folder . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test-secret',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
        ]);
        $container->extension('twig', ['strict_variables' => true]);
        $container->extension('security', [
            'providers' => ['cattophp' => ['id' => SignInUserProvider::class]],
            'firewalls' => ['main' => [
                'lazy' => true,
                'provider' => 'cattophp',
                'custom_authenticators' => [SignInAuthenticator::class],
                'entry_point' => SignInEntryPoint::class,
            ]],
            'access_control' => [
                ['path' => '^/(sign-in|open)', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/', 'roles' => 'ROLE_USER'],
            ],
        ]);
        $container->extension('cattophp', [
            'application_key' => 'cattophp-test-key-not-used-anywhere',
            'storage' => $this->databaseUrl === null ? ['type' => 'files', 'folder' => $this->folder . '/storage'] : ['type' => 'database', 'connection' => 'test.connection'],
            'starting_values' => $this->starting + [
                'APP_NAME' => 'Example Site',
                'APP_URL' => 'https://example.com',
                'APP_ADMIN_EMAIL' => 'owner@example.com',
                'MAIL_FROM_ADDRESS' => 'site@example.com',
                'APP_SITE_MODE' => 'live',
            ],
            'sign_in' => ['after' => '/'],
            // A directive with a hyphen, as a project writes it in YAML.
            'security_headers' => ['policy' => ['img-src' => "'self'"]],
        ]);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(PagesController::class)->public()->tag('controller.service_arguments');
        $services->set(RecordingTransport::class)->public();
        $services->set('logger', RecordingLogger::class)->public();
        $services->set(Mailer::class)->args([
            service(Settings::class), service(Installation::class), service(SiteMode::class), service('twig'), service('clock'), service(RecordingTransport::class),
        ]);
        if ($this->databaseUrl !== null) {
            $services->set('test.connection', Connection::class)
                ->factory([DriverManager::class, 'getConnection'])
                ->args([(new DsnParser())->parse($this->databaseUrl)])
                ->public();
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(dirname(__DIR__, 3) . '/config/routes/sign_in.php');
        $routes->add('home', '/')->controller([PagesController::class, 'home']);
        $routes->add('settings_page', '/settings')->controller([PagesController::class, 'settings']);
        $routes->add('broken', '/open/broken')->controller([PagesController::class, 'broken']);
        $routes->add('refused', '/open/refused')->controller([PagesController::class, 'refused'])->methods(['POST']);
    }
}
