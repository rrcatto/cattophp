<?php

declare(strict_types=1);

namespace CattoPHP;

use CattoPHP\Sessions\DbalSessionStore;
use CattoPHP\Sessions\FileSessionStore;
use CattoPHP\Sessions\SessionStore;
use CattoPHP\Links\DbalLinkStore;
use CattoPHP\Links\FileLinkStore;
use CattoPHP\Links\LinkStore;
use CattoPHP\Access\DbalRoleStore;
use CattoPHP\Access\FoundationPermissions;
use CattoPHP\Access\MemoryRoleStore;
use CattoPHP\Access\PermissionCatalogue;
use CattoPHP\Access\PermissionProvider;
use CattoPHP\Access\PermissionVoter;
use CattoPHP\Access\Roles;
use CattoPHP\Access\RoleStore;
use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\ActivityStore;
use CattoPHP\Activity\DbalActivityStore;
use CattoPHP\Activity\FileActivityStore;
use CattoPHP\Http\ContentSecurityPolicy;
use CattoPHP\Http\HtmlLayout;
use CattoPHP\Http\ErrorPageListener;
use CattoPHP\Http\TwigErrorPageDrawer;
use CattoPHP\Http\FormToken;
use CattoPHP\Http\RequestReference;
use CattoPHP\Http\SecurityHeadersListener;
use CattoPHP\Http\Throttle;
use CattoPHP\Http\TypedFormListener;
use CattoPHP\Installation\Installation;
use CattoPHP\Mail\Mailer;
use CattoPHP\Mail\TestEmailCommand;
use CattoPHP\Payments\GatewayDefinitions;
use CattoPHP\Payments\PaymentGateways;
use CattoPHP\Payments\PaymentSettings;
use CattoPHP\Runtime\SiteMode;
use CattoPHP\Schema\FoundationSteps;
use CattoPHP\Schema\MigrateCommand;
use CattoPHP\Schema\SchemaRunner;
use CattoPHP\Schema\SchemaStep;
use CattoPHP\Security\KeyedHash;
use CattoPHP\Security\SecretCipher;
use CattoPHP\Security\TwoStep\DbalTwoStepStore;
use CattoPHP\Security\TwoStep\FileTwoStepStore;
use CattoPHP\Security\TwoStep\TwoStep;
use CattoPHP\Security\TwoStep\TwoStepStore;
use CattoPHP\Settings\DbalSettingsStore;
use CattoPHP\Settings\EnvironmentValues;
use CattoPHP\Settings\FoundationSettings;
use CattoPHP\Settings\MemorySettingsStore;
use CattoPHP\Settings\Settings;
use CattoPHP\Settings\SettingsCatalogue;
use CattoPHP\Settings\SettingsProvider;
use CattoPHP\Settings\SettingsStore;
use CattoPHP\SignIn\OwnerOnlyAccounts;
use CattoPHP\SignIn\SignInAccounts;
use CattoPHP\SignIn\SignInAuthenticator;
use CattoPHP\SignIn\SignInController;
use CattoPHP\SignIn\SignInEntryPoint;
use CattoPHP\SignIn\SignInFlow;
use CattoPHP\SignIn\SignInLinkCommand;
use CattoPHP\SignIn\SignInLinks;
use CattoPHP\SignIn\SignInRules;
use CattoPHP\SignIn\SignInUserProvider;
use CattoPHP\SignIn\TwoStepResetCommand;
use CattoPHP\Twig\CattoPHPExtension;
use CattoPHP\Twig\UiExtension;
use CattoPHP\Theme\InstallThemesCommand;
use CattoPHP\Theme\MemoryThemeChoice;
use CattoPHP\Theme\PackageValidator;
use CattoPHP\Theme\PageRenderer;
use CattoPHP\Theme\Publisher;
use CattoPHP\Theme\Templates;
use CattoPHP\Theme\ThemeManager;
use CattoPHP\Ui\Components;
use CattoPHP\Ui\ComponentSet;
use CattoPHP\Ui\DrawingCheck;
use CattoPHP\Ui\IconLibrary;
use InvalidArgumentException;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\HttpKernel\KernelEvents;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * CattoPHP in a Symfony project. Its configuration (`config/packages/cattophp.yaml`) says where its records are kept
 * (the database, or files for an installation without one), which foundations are on, and how the pages and headers
 * behave. The README shows a whole configuration.
 */
final class CattoPHPBundle extends AbstractBundle
{
    protected string $extensionAlias = 'cattophp';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('application_key')->isRequired()->cannotBeEmpty()->info('The key secrets are encrypted and codes hashed with: %env(APP_KEY)%')->end()
                ->arrayNode('storage')->addDefaultsIfNotSet()->children()
                    ->enumNode('type')->values(['database', 'files'])->defaultValue('database')->end()
                    ->scalarNode('connection')->defaultValue('doctrine.dbal.default_connection')->info('The DBAL connection\'s service id, for the database')->end()
                    ->scalarNode('folder')->defaultNull()->info('The folder for files, only the site\'s own account can read')->end()
                ->end()->end()
                ->booleanNode('site_mode')->defaultTrue()->info('Whether the site is a demo or live; false for a project without the distinction')->end()
                ->scalarNode('layout')->defaultValue('@CattoPHP/layout.html.twig')->info('The template CattoPHP\'s pages extend')->end()
                ->arrayNode('starting_values')->normalizeKeys(false)->useAttributeAsKey('name')->scalarPrototype()->end()->info('Starting values in place of .env\'s, by name')->end()
                ->arrayNode('defaults')->normalizeKeys(false)->useAttributeAsKey('name')->scalarPrototype()->end()->info('The project\'s own defaults, by name: used when .env and the Settings page give nothing')->end()
                ->arrayNode('sign_in')->canBeDisabled()->children()
                    ->scalarNode('accounts')->defaultValue(OwnerOnlyAccounts::class)->info('The service that finds the application\'s accounts')->end()
                    ->scalarNode('after')->defaultValue('/')->info('Where a person goes after signing in')->end()
                    ->booleanNode('recovery_codes')->defaultTrue()->end()
                    ->scalarNode('two_step_file')->defaultNull()->info('With files: the file of the two-step key, {account} for one per account')->end()
                ->end()->end()
                ->arrayNode('security_headers')->canBeDisabled()->children()
                    ->arrayNode('policy')->normalizeKeys(false)->useAttributeAsKey('directive')->scalarPrototype()->end()->info('Content-Security-Policy directives replaced or added')->end()
                    ->scalarNode('referrer_policy')->defaultValue('strict-origin-when-cross-origin')->end()
                    ->scalarNode('permissions_policy')->defaultValue(SecurityHeadersListener::PERMISSIONS_POLICY)->info('What the browser may let a page use, such as microphone=(self) for a page that records')->end()
                    ->booleanNode('no_index')->defaultFalse()->info('Ask search engines to keep away, demo or live')->end()
                    ->booleanNode('no_store')->defaultFalse()->info('Ask browsers to keep no copy of any page')->end()
                ->end()->end()
                ->arrayNode('html_layout')->addDefaultsIfNotSet()->children()
                    ->arrayNode('verbatim_classes')->scalarPrototype()->end()->info('Classes of the elements that hold a project\'s authored HTML, which the layout serves byte for byte')->end()
                    ->arrayNode('flex_classes')->scalarPrototype()->end()->info('Classes the project\'s CSS always lays out as flex or grid, whose children the layout puts on lines of their own')->end()
                ->end()->end()
                ->arrayNode('ui')->addDefaultsIfNotSet()->children()
                    ->scalarNode('icon_sprite')->defaultValue('/img/icons.svg')->info('The icon sprite\'s address on the site, whose symbols the icon component draws')->end()
                    ->scalarNode('public_root')->defaultValue('%kernel.project_dir%/public')->info('The served web root, where the icon sprite is found for its fingerprint')->end()
                    ->arrayNode('icon_subjects')->useAttributeAsKey('word')->arrayPrototype()->scalarPrototype()->end()->end()->info('The subjects the project\'s names are about, each with the library icons that picture it best')->end()
                    ->arrayNode('icon_name_stop_words')->scalarPrototype()->end()->info('More words the project\'s names use that say nothing about a picture')->end()
                ->end()->end()
                ->arrayNode('theme')->canBeEnabled()->children()
                    ->scalarNode('default')->defaultValue('default')->info('The slug of the bundled theme a site is drawn in until another is chosen')->end()
                    ->scalarNode('package_format')->defaultValue('cattophp-theme')->info('The package format\'s name, as a theme\'s theme.json says it')->end()
                    ->arrayNode('page_families')->scalarPrototype()->end()->info('The page families a theme may wrap with pages/<family>.html.twig')->end()
                    ->scalarNode('bundled')->defaultValue('%kernel.project_dir%/themes')->info('The project\'s own themes, in its code')->end()
                    ->scalarNode('installed')->defaultValue('%kernel.project_dir%/var/themes')->info('The site\'s installed themes')->end()
                    ->scalarNode('staging')->defaultValue('%kernel.project_dir%/var/theme-imports')->info('Where an uploaded theme package waits to be installed')->end()
                    ->scalarNode('public_root')->defaultValue('%kernel.project_dir%/public')->info('The served web root, whose themes/ folder holds each theme\'s browser files')->end()
                    ->scalarNode('choice')->defaultValue(MemoryThemeChoice::class)->info('The service that keeps which theme is active (CattoPHP\Theme\ThemeChoice)')->end()
                    ->scalarNode('registry')->defaultNull()->info('An optional service that indexes the installed themes (CattoPHP\Theme\ThemeRegistry)')->end()
                    ->scalarNode('pages')->defaultNull()->info('The service that tells PageRenderer about the project\'s pages (CattoPHP\Theme\PageProject), for a project that draws its pages with it')->end()
                ->end()->end()
                ->arrayNode('payments')->canBeEnabled()->children()
                    ->arrayNode('gateways')->info('Each payment gateway by the project\'s name for it, through Omnipay')->useAttributeAsKey('name')->arrayPrototype()->children()
                        ->scalarNode('omnipay')->isRequired()->cannotBeEmpty()->info('The Omnipay gateway: a short name such as Stitch, or a class')->end()
                        ->scalarNode('label')->isRequired()->cannotBeEmpty()->info('Its name on the Settings page')->end()
                        ->scalarNode('description')->defaultValue('')->end()
                        ->arrayNode('parameters')->isRequired()->requiresAtLeastOneElement()->normalizeKeys(false)->useAttributeAsKey('parameter')->arrayPrototype()->children()
                            ->scalarNode('name')->isRequired()->cannotBeEmpty()->info('The setting that holds it, named as in .env')->end()
                            ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
                            ->booleanNode('secret')->defaultFalse()->info('Stored encrypted, never shown again')->end()
                            ->booleanNode('optional')->defaultFalse()->end()
                            ->scalarNode('help')->defaultValue('')->end()
                        ->end()->end()->end()
                    ->end()->end()->end()
                ->end()->end()
                ->arrayNode('error_pages')->canBeDisabled()->children()
                    ->arrayNode('skip_paths')->scalarPrototype()->end()->info('Path prefixes whose errors the project answers itself, such as /api/')->end()
                    ->scalarNode('drawer')->defaultValue(TwigErrorPageDrawer::class)->info('The service that draws an error page in the project\'s look (CattoPHP\Http\ErrorPageDrawer)')->end()
                ->end()->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Form tokens live in Symfony's session. A project that keeps the session off (one that runs its own) gets none,
        // rather than a container Symfony refuses to build.
        if (self::sessionIsOn($builder)) {
            $builder->prependExtensionConfig('framework', ['csrf_protection' => true]);
        }
    }

    /** Whether the project's own configuration leaves Symfony's session on. */
    private static function sessionIsOn(ContainerBuilder $builder): bool
    {
        foreach ($builder->getExtensionConfig('framework') as $config) {
            $session = $config['session'] ?? null;
            if ($session === false || (is_array($session) && ($session['enabled'] ?? true) === false)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array{type:string,connection:string,folder:?string} $storage */
        $storage = $config['storage'];
        /** @var array{enabled:bool,accounts:string,after:string,recovery_codes:bool,two_step_file:?string} $signIn */
        $signIn = $config['sign_in'];
        /** @var array{enabled:bool,policy:array<string,string>,referrer_policy:string,permissions_policy:string,no_index:bool,no_store:bool} $headers */
        $headers = $config['security_headers'];
        /** @var array{enabled:bool,skip_paths:list<string>,drawer:string} $errors */
        $errors = $config['error_pages'];
        /** @var array{verbatim_classes:list<string>,flex_classes:list<string>} $layout */
        $layout = $config['html_layout'];
        /** @var array{icon_sprite:string,public_root:string,icon_subjects:array<string,list<string>>,icon_name_stop_words:list<string>} $ui */
        $ui = $config['ui'];
        $database = $storage['type'] === 'database';
        if (!$database && ($storage['folder'] === null || $storage['folder'] === '')) {
            throw new InvalidArgumentException('cattophp.storage.folder must name a folder when cattophp.storage.type is files.');
        }
        $folder = rtrim((string) $storage['folder'], '/');

        $builder->registerForAutoconfiguration(SettingsProvider::class)->addTag(SettingsProvider::TAG);
        $builder->registerForAutoconfiguration(PermissionProvider::class)->addTag(PermissionProvider::TAG);
        $builder->registerForAutoconfiguration(SchemaStep::class)->addTag(SchemaStep::TAG);
        $builder->registerForAutoconfiguration(ComponentSet::class)->addTag(ComponentSet::TAG);
        $builder->registerForAutoconfiguration(DrawingCheck::class)->addTag(DrawingCheck::TAG);

        $services = $container->services()->defaults()->private()->autowire();
        $container->parameters()->set('cattophp.application_key', $config['application_key']);

        $services->set(SecretCipher::class)->args([param('cattophp.application_key')]);
        $services->set(KeyedHash::class)->args([param('cattophp.application_key')]);
        $services->set(RequestReference::class);
        $services->set(EnvironmentValues::class)->args([null, $config['starting_values']]);

        // Where the records are kept.
        if ($database) {
            $connection = service($storage['connection']);
            $services->set(SettingsStore::class, DbalSettingsStore::class)->args([$connection]);
            $services->set(ActivityStore::class, DbalActivityStore::class)->args([$connection]);
            $services->set(RoleStore::class, DbalRoleStore::class)->args([$connection]);
            $services->set(LinkStore::class, DbalLinkStore::class)->args([$connection]);
            $services->set(SessionStore::class, DbalSessionStore::class)->args([$connection]);
            $services->set(TwoStepStore::class, DbalTwoStepStore::class)->args([$connection]);
            foreach (FoundationSteps::classes() as $step) {
                $services->set($step)->tag(SchemaStep::TAG);
            }
            $services->set(SchemaRunner::class)->args([$connection, tagged_iterator(SchemaStep::TAG)]);
            $services->set(MigrateCommand::class)->tag('console.command');
        } else {
            $services->set(SettingsStore::class, MemorySettingsStore::class)->args([false]);
            $services->set(ActivityStore::class, FileActivityStore::class)->args([$folder]);
            $services->set(RoleStore::class, MemoryRoleStore::class)->args([false]);
            $services->set(LinkStore::class, FileLinkStore::class)->args([$folder . '/links.json']);
            $services->set(SessionStore::class, FileSessionStore::class)->args([$folder . '/sessions.json']);
            $services->set(TwoStepStore::class, FileTwoStepStore::class)->args([$signIn['two_step_file'] ?? $folder . '/two-step/{account}.json']);
        }

        // The foundations.
        $services->set(ActivityLog::class)->args([service(ActivityStore::class), service('clock'), service('request_stack')->nullOnInvalid(), service('security.token_storage')->nullOnInvalid()]);
        $services->set(FoundationSettings::class)->args([$config['site_mode'], $signIn['enabled']])->tag(SettingsProvider::TAG);
        $services->set(SettingsCatalogue::class)->args([tagged_iterator(SettingsProvider::TAG), $config['defaults']]);
        $services->set(Settings::class);
        $services->set(Installation::class);
        $services->set(SiteMode::class)->args([$config['site_mode'] ? service(Settings::class) : null]);
        $services->set(FoundationPermissions::class)->tag(PermissionProvider::TAG);
        $services->set(PermissionCatalogue::class)->args([tagged_iterator(PermissionProvider::TAG)]);
        $services->set(Roles::class);
        $services->set(PermissionVoter::class)->tag('security.voter');
        $services->set(Throttle::class)->args([service('cache.app')]);
        $services->set(Mailer::class)->args([
            service(Settings::class), service(Installation::class), service(SiteMode::class), service('twig'), service('clock'), null,
            service('mailer.transport_factory')->nullOnInvalid(),
        ]);
        $services->set(TestEmailCommand::class)->tag('console.command');
        $services->set(FormToken::class)->args([service('security.csrf.token_manager')->nullOnInvalid()]);
        $services->set(ContentSecurityPolicy::class)->args([service('request_stack'), $headers['policy']]);
        // The served page's layout, which a project calls on a whole page it has drawn (HtmlLayout::document).
        $services->set(HtmlLayout::class)->args([$layout['verbatim_classes'], $layout['flex_classes']])->public();
        // Added before the project's own extensions, so a project function, filter or global of the same name wins.
        $services->set(CattoPHPExtension::class)->arg('$layout', $config['layout'])->tag('twig.extension', ['priority' => 100]);
        // The component registry, with the project's own components and the drawings it allows in a feature card.
        $services->set(Components::class)->args([tagged_iterator(ComponentSet::TAG), tagged_iterator(DrawingCheck::TAG), $ui['icon_sprite'], $ui['public_root']])->public();
        $services->set(IconLibrary::class)->args([$ui['icon_subjects'], $ui['icon_name_stop_words']])->public();
        $services->set(UiExtension::class)->args([service(Components::class), service(IconLibrary::class)])->tag('twig.extension', ['priority' => 100]);
        // Payment gateways through Omnipay, each one's keys in Settings, for a project that takes payments.
        $payments = $config['payments'];
        if ($payments['enabled']) {
            $services->set(GatewayDefinitions::class)->args([$payments['gateways']]);
            $services->set(PaymentSettings::class)->args([service(GatewayDefinitions::class)])->tag(SettingsProvider::TAG);
            $services->set(PaymentGateways::class)->args([service(GatewayDefinitions::class), service(Settings::class), service('request_stack')->nullOnInvalid()])->public();
        }
        // The theme system, for a project with themes: their packages checked, installed, published, chosen and previewed,
        // and Twig's @theme pointed at the theme a request is drawn in.
        $theme = $config['theme'];
        // CattoPHP's own pages (sign-in, and the error page when CattoPHP draws it) wear the theme when the project draws its pages with it.
        $themedPages = $theme['enabled'] && $theme['pages'] !== null;
        if ($theme['enabled']) {
            $services->set(PackageValidator::class)->args([$theme['package_format'], $theme['page_families']]);
            $services->set(Publisher::class);
            if ($theme['choice'] === MemoryThemeChoice::class) {
                $services->set(MemoryThemeChoice::class);
            }
            $services->set(ThemeManager::class)->args([
                $theme['bundled'], $theme['installed'], $theme['staging'], $theme['public_root'], $theme['default'],
                service($theme['choice']), service(ActivityLog::class), $theme['registry'] !== null ? service($theme['registry']) : null,
                service(PackageValidator::class), service(Publisher::class),
            ])->public();
            $services->set(Templates::class)->args([service('twig.loader.native_filesystem')])->public();
            $services->set(InstallThemesCommand::class)->args([service(ThemeManager::class)])->tag('console.command');
            if ($theme['pages'] !== null) {
                $services->set(PageRenderer::class)
                    ->args([service('twig'), service(Templates::class), service(ThemeManager::class), service($theme['pages']), service(HtmlLayout::class), param('kernel.debug')])
                    ->public();
            }
        }

        if ($headers['enabled']) {
            $services->set(SecurityHeadersListener::class)
                ->args([service(ContentSecurityPolicy::class), service(SiteMode::class), $headers['referrer_policy'], $headers['no_index'], $headers['no_store'], $headers['permissions_policy']])
                ->tag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onRequest', 'priority' => 512])
                ->tag('kernel.event_listener', ['event' => KernelEvents::RESPONSE, 'method' => 'onResponse', 'priority' => -16]);
        }
        $services->set(TypedFormListener::class)
            ->tag('kernel.event_listener', ['event' => KernelEvents::RESPONSE, 'method' => 'onResponse', 'priority' => -32]);
        if ($errors['enabled']) {
            if ($errors['drawer'] === TwigErrorPageDrawer::class) {
                $services->set(TwigErrorPageDrawer::class)->args([service('twig'), $themedPages ? service(PageRenderer::class) : null]);
            }
            $services->set(ErrorPageListener::class)
                ->args([service($errors['drawer']), service('logger'), param('kernel.debug'), $errors['skip_paths']])
                ->tag('kernel.event_listener', ['event' => KernelEvents::EXCEPTION, 'method' => 'onException', 'priority' => -16]);
        }

        if ($signIn['enabled']) {
            $services->set(TwoStep::class)->arg('$recoveryCodes', $signIn['recovery_codes']);
            if ($signIn['accounts'] === OwnerOnlyAccounts::class) {
                $services->set(OwnerOnlyAccounts::class);
            }
            $services->alias(SignInAccounts::class, $signIn['accounts']);
            $services->set(SignInRules::class)->args([service(Settings::class), $database ? service(Roles::class) : null]);
            $services->set(SignInFlow::class);
            $services->set(SignInLinks::class)->arg('$urls', service('router'));
            $services->set(SignInUserProvider::class);
            $services->set(SignInAuthenticator::class);
            $services->set(SignInEntryPoint::class)->args([service('router')]);
            $services->set(SignInController::class)
                ->args([
                    service(SignInLinks::class), service(SignInAccounts::class), service(SignInRules::class), service(SignInFlow::class),
                    service(TwoStep::class), service(FormToken::class), service('security.helper'), service(Installation::class),
                    service(ActivityLog::class), service('twig'), service('router'), service('logger'), service('clock'), $signIn['after'],
                    $themedPages ? service(PageRenderer::class) : null,
                ])
                ->public()
                ->tag('controller.service_arguments');
            $services->set(SignInLinkCommand::class)->tag('console.command');
            $services->set(TwoStepResetCommand::class)->tag('console.command');
        }
    }
}
