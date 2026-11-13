<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel\App;

use CattoPHP\CattoPHPBundle;
use CattoPHP\Installation\Installation;
use CattoPHP\Mail\Mailer;
use CattoPHP\Runtime\SiteMode;
use CattoPHP\Settings\Settings;
use CattoPHP\Api\ApiTokenAuthenticator;
use CattoPHP\SignIn\SignInAuthenticator;
use CattoPHP\SignIn\SignInEntryPoint;
use CattoPHP\SignIn\SignInUserProvider;
use CattoPHP\ServiceTags;
use CattoPHP\Tests\Support\EveryExtension;
use CattoPHP\Tests\Support\ExampleSetupSteps;
use CattoPHP\Tests\Support\RecordingLogger;
use CattoPHP\Tests\Support\RecordingTransport;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
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

    /**
     * @param array<string,string> $starting starting values in place of .env's
     * @param bool $ownSessions a project that runs its own PHP sessions, with Symfony's off and CattoPHP's sign-in pages off
     * @param array{bundled:string,installed:string,staging:string,public_root:string,pages_folder:string}|null $theme a project with themes, its folders and its pages' templates
     * @param bool $scheduler a project with background work: the scheduler on, its old command name kept, one task of its own
     * @param bool $setup a project with a setup checklist: its own steps
     * @param bool $protection a public site with site protection, its task late among the scheduled ones
     * @param bool $telegram a project with a Telegram bot, its owner's choices
     * @param bool $search a public site with search engines and AI: its public pages, sitemap sources and llms.txt
     * @param bool $documents a project that draws documents and PDFs
     * @param bool $api a project with an API: its firewall, a scope and two routes
     * @param bool $stockPages CattoPHP's Administration pages and Sign-in and security, their route files imported
     * @param bool $accounts the project's own accounts (ExampleAccounts), where people join by signing in, in place of the owner alone
     * @param bool $signIns the sign-ins a person can see and end kept (sign_in.sessions)
     * @param bool $projectStep a schema step of the project's own
     * @param bool $debug the kernel in debug, as a development site's is (RunMode::debug()); the tests' kernel is not
     * @param bool $everyExtension one class of the project's implementing every extension interface (EveryExtension), its
     *                             tags kept as the parameter test.every_extension_tags
     */
    public function __construct(private readonly string $folder, private readonly ?string $databaseUrl = null, private readonly array $starting = [], private readonly bool $ownSessions = false, private readonly ?array $theme = null, private readonly bool $scheduler = false, private readonly bool $setup = false, private readonly bool $protection = false, private readonly bool $telegram = false, private readonly bool $search = false, private readonly bool $documents = false, private readonly bool $api = false, private readonly bool $signup = false, private readonly bool $identity = false, private readonly bool $inbox = false, private readonly bool $mailing = false, private readonly bool $stockPages = false, private readonly bool $accounts = false, private readonly bool $signIns = false, private readonly bool $projectStep = false, bool $debug = false, private readonly bool $everyExtension = false)
    {
        parent::__construct('test', $debug);
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
        return $this->folder . '/cache/' . md5(serialize([$this->databaseUrl, $this->starting, $this->ownSessions, $this->theme, $this->scheduler, $this->setup, $this->protection, $this->telegram, $this->search, $this->documents, $this->api, $this->signup, $this->identity, $this->inbox, $this->mailing, $this->stockPages, $this->accounts, $this->signIns, $this->projectStep, $this->debug, $this->everyExtension]));
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
            'session' => $this->ownSessions ? ['enabled' => false] : ['storage_factory_id' => 'session.storage.factory.mock_file'],
        ] + ($this->telegram || $this->signup || $this->identity || $this->mailing ? ['http_client' => ['enabled' => true]] : [])
            // A project sending news usually has Symfony's mailer too, whose transport nothing of mailing's may take by mistake.
            + ($this->mailing ? ['mailer' => ['dsn' => 'null://null']] : []));
        $container->extension('twig', ['strict_variables' => true] + ($this->theme !== null ? ['paths' => [$this->theme['pages_folder'] => 'platform']] : []));
        if ($this->ownSessions) {
            $container->extension('security', ['providers' => ['none' => ['memory' => null]], 'firewalls' => ['main' => ['security' => false]]]);
        } else {
            $container->extension('security', [
            'providers' => ['cattophp' => ['id' => SignInUserProvider::class]],
            'firewalls' => ($this->api ? ['api' => [
                'pattern' => '^/api/',
                'stateless' => true,
                'provider' => 'cattophp',
                'custom_authenticators' => [ApiTokenAuthenticator::class],
                'entry_point' => ApiTokenAuthenticator::class,
            ]] : []) + ['main' => [
                'lazy' => true,
                'provider' => 'cattophp',
                'custom_authenticators' => [SignInAuthenticator::class],
                'entry_point' => SignInEntryPoint::class,
            ]],
            'access_control' => [
                ['path' => '^/(sign-in|open|api)', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/', 'roles' => 'ROLE_USER'],
            ],
            ]);
        }
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
            'sign_in' => $this->ownSessions ? ['enabled' => false] : ['after' => '/'] + ($this->accounts ? ['accounts' => ExampleAccounts::class] : []) + ($this->signIns ? ['sessions' => true] : []),
            // A directive with a hyphen, as a project writes it in YAML.
            'security_headers' => ['policy' => ['img-src' => "'self'"]],
        ] + ($this->theme !== null ? ['theme' => [
            'default' => 'plain', 'bundled' => $this->theme['bundled'], 'installed' => $this->theme['installed'], 'staging' => $this->theme['staging'],
            'public_root' => $this->theme['public_root'], 'package_format' => 'cattophp-theme', 'page_families' => ['admin'], 'pages' => ExamplePages::class,
        ]] : []) + ($this->scheduler ? ['scheduler' => ['enabled' => true, 'command_alias' => 'schedule:run', 'log_archive' => ['enabled' => true, 'folder' => $this->folder . '/logs']]] : [])
            + ($this->setup ? ['setup' => ['enabled' => true]] : [])
            + ($this->protection ? ['protection' => ['enabled' => true, 'firewall_folder' => $this->folder . '/server-firewall', 'secret_paths' => ['/open/'], 'task_priority' => -30]] : [])
            + ($this->telegram ? ['telegram' => ['enabled' => true, 'choices' => ['on_sale'], 'task_priority' => -40]] : [])
            + ($this->search ? ['search' => ['enabled' => true, 'private_paths' => [['why' => 'Signed-in pages.', 'paths' => ['/account/', '/admin/']]], 'share_pictures' => $this->folder . '/share-pictures', 'llms_txt' => ExampleSitemapGuides::class]] : [])
            + ($this->documents ? ['documents' => ['enabled' => true, 'font_cache' => $this->folder . '/dompdf-fonts']] : [])
            + ($this->api ? ['api' => ['enabled' => true, 'token_prefix' => 'example_', 'scopes' => ['example:read' => 'Read the example.'], 'accounts' => ExampleApiAccounts::class]] : [])
            + ($this->signup ? ['network' => ['lists_folder' => $this->folder . '/network-lists'], 'signup' => ['enabled' => true, 'task_priority' => 5]] : [])
            + ($this->identity ? ['identity' => ['enabled' => true, 'return_path' => '/account/verification/return', 'account_detail' => 'user_id', 'minimum_age' => 18]] : [])
            + ($this->inbox ? ['inbox' => ['enabled' => true, 'files_folder' => $this->folder . '/inbox-files', 'task_priority' => -60]] : [])
            + ($this->mailing ? ['mailing' => ['enabled' => true, 'project' => 'example']] : []));

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(PagesController::class)->public()->tag('controller.service_arguments');
        $services->set(ProjectExtension::class);
        if ($this->api) {
            $services->set(ExampleApiAccounts::class);
            $services->set(ExampleApiController::class)->public()->tag('controller.service_arguments');
        }
        if ($this->search) {
            $services->set(ExampleSitemapPages::class);
            $services->set(ExampleSitemapGuides::class);
        }
        if ($this->stockPages) {
            // Found by autoconfiguration, as a project's own words and links are.
            $services->set(ExampleDetailWords::class);
            $services->set(ExampleSubjectLinks::class);
        }
        if ($this->scheduler) {
            // Found by autoconfiguration, as a project's own tasks are.
            $services->set(ExampleKernelTask::class);
        }
        if ($this->theme !== null) {
            $services->set(ExamplePages::class);
        }
        if ($this->accounts) {
            $services->set(ExampleAccounts::class)->public();
        }
        if ($this->projectStep) {
            // Found by autoconfiguration, as a project's own steps are.
            $services->set(ExampleSchemaStep::class);
        }
        if ($this->setup) {
            // Found by autoconfiguration, as a project's own steps are.
            $services->set(ExampleSetupSteps::class)->public();
        }
        if ($this->everyExtension) {
            // Found by autoconfiguration under every tag at once.
            $services->set(EveryExtension::class)->public();
        }
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

    protected function build(ContainerBuilder $container): void
    {
        if ($this->everyExtension) {
            // The tags autoconfiguration gave the one class, read once every pass that adds tags has run.
            $container->addCompilerPass(new class implements CompilerPassInterface {
                public function process(ContainerBuilder $container): void
                {
                    $tags = array_keys($container->getDefinition(EveryExtension::class)->getTags());
                    $container->setParameter('test.every_extension_tags', array_values(array_intersect($tags, ServiceTags::EXTENSION_POINTS)));
                }
            }, PassConfig::TYPE_BEFORE_REMOVING);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        if (!$this->ownSessions) {
            $routes->import(dirname(__DIR__, 3) . '/config/routes/sign_in.php');
        }
        if ($this->stockPages) {
            $routes->import(dirname(__DIR__, 3) . '/config/routes/admin.php');
            $routes->import(dirname(__DIR__, 3) . '/config/routes/account.php');
        }
        $routes->add('home', '/')->controller([PagesController::class, 'home']);
        $routes->add('settings_page', '/settings')->controller([PagesController::class, 'settings']);
        $routes->add('broken', '/open/broken')->controller([PagesController::class, 'broken']);
        $routes->add('refused', '/open/refused')->controller([PagesController::class, 'refused'])->methods(['POST']);
        $routes->add('form', '/open/form')->controller([PagesController::class, 'form'])->methods(['GET']);
        if ($this->api) {
            $routes->add('api_me', '/api/me')->controller([ExampleApiController::class, 'me'])->methods(['GET']);
            $routes->add('api_missing', '/api/missing')->controller([ExampleApiController::class, 'missing'])->methods(['GET']);
            $routes->add('api_invalid', '/api/invalid')->controller([ExampleApiController::class, 'invalid'])->methods(['GET']);
        }
    }
}
