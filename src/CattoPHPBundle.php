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
use CattoPHP\Http\ErrorPageListener;
use CattoPHP\Http\FormToken;
use CattoPHP\Http\RequestReference;
use CattoPHP\Http\SecurityHeadersListener;
use CattoPHP\Http\Throttle;
use CattoPHP\Http\TypedFormListener;
use CattoPHP\Installation\Installation;
use CattoPHP\Mail\Mailer;
use CattoPHP\Mail\TestEmailCommand;
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
                    ->booleanNode('no_index')->defaultFalse()->info('Ask search engines to keep away, demo or live')->end()
                    ->booleanNode('no_store')->defaultFalse()->info('Ask browsers to keep no copy of any page')->end()
                ->end()->end()
                ->arrayNode('error_pages')->canBeDisabled()->children()
                    ->arrayNode('skip_paths')->scalarPrototype()->end()->info('Path prefixes whose errors the project answers itself, such as /api/')->end()
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
        /** @var array{enabled:bool,policy:array<string,string>,referrer_policy:string,no_index:bool,no_store:bool} $headers */
        $headers = $config['security_headers'];
        /** @var array{enabled:bool,skip_paths:list<string>} $errors */
        $errors = $config['error_pages'];
        $database = $storage['type'] === 'database';
        if (!$database && ($storage['folder'] === null || $storage['folder'] === '')) {
            throw new InvalidArgumentException('cattophp.storage.folder must name a folder when cattophp.storage.type is files.');
        }
        $folder = rtrim((string) $storage['folder'], '/');

        $builder->registerForAutoconfiguration(SettingsProvider::class)->addTag(SettingsProvider::TAG);
        $builder->registerForAutoconfiguration(PermissionProvider::class)->addTag(PermissionProvider::TAG);
        $builder->registerForAutoconfiguration(SchemaStep::class)->addTag(SchemaStep::TAG);

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
        // Added before the project's own extensions, so a project function, filter or global of the same name wins.
        $services->set(CattoPHPExtension::class)->arg('$layout', $config['layout'])->tag('twig.extension', ['priority' => 100]);

        if ($headers['enabled']) {
            $services->set(SecurityHeadersListener::class)
                ->args([service(ContentSecurityPolicy::class), service(SiteMode::class), $headers['referrer_policy'], $headers['no_index'], $headers['no_store']])
                ->tag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onRequest', 'priority' => 512])
                ->tag('kernel.event_listener', ['event' => KernelEvents::RESPONSE, 'method' => 'onResponse', 'priority' => -16]);
        }
        $services->set(TypedFormListener::class)
            ->tag('kernel.event_listener', ['event' => KernelEvents::RESPONSE, 'method' => 'onResponse', 'priority' => -32]);
        if ($errors['enabled']) {
            $services->set(ErrorPageListener::class)
                ->args([service('twig'), service('logger'), param('kernel.debug'), $errors['skip_paths']])
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
                ])
                ->public()
                ->tag('controller.service_arguments');
            $services->set(SignInLinkCommand::class)->tag('console.command');
            $services->set(TwoStepResetCommand::class)->tag('console.command');
        }
    }
}
