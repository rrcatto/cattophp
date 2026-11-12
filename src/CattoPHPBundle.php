<?php

declare(strict_types=1);

namespace CattoPHP;

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
use CattoPHP\Api\ApiAccess;
use CattoPHP\Api\ApiAccounts;
use CattoPHP\Api\ApiExceptionListener;
use CattoPHP\Api\ApiScopeVoter;
use CattoPHP\Api\ApiTokenAuthenticator;
use CattoPHP\Api\ApiTokens;
use CattoPHP\Document\DocumentImages;
use CattoPHP\Document\DocumentRenderer;
use CattoPHP\Document\PdfRenderer;
use CattoPHP\Document\TemplateValidator;
use CattoPHP\Document\ValueFormatter;
use CattoPHP\Http\ContentSecurityPolicy;
use CattoPHP\Http\ErrorPageListener;
use CattoPHP\Http\FormStamp;
use CattoPHP\Http\FormToken;
use CattoPHP\Http\HtmlLayout;
use CattoPHP\Http\RequestReference;
use CattoPHP\Http\SecurityHeadersListener;
use CattoPHP\Http\Throttle;
use CattoPHP\Http\TwigErrorPageDrawer;
use CattoPHP\Http\TypedFormListener;
use CattoPHP\Identity\DiditClient;
use CattoPHP\Identity\IdentityAccounts;
use CattoPHP\Identity\IdentityApproved;
use CattoPHP\Identity\IdentityChecks;
use CattoPHP\Identity\IdentitySettings;
use CattoPHP\Identity\IdentityVerification;
use CattoPHP\Inbox\ImapMailbox;
use CattoPHP\Inbox\Inbox;
use CattoPHP\Inbox\InboxDocuments;
use CattoPHP\Inbox\InboxMailbox;
use CattoPHP\Inbox\InboxMailboxTask;
use CattoPHP\Inbox\InboxReplies;
use CattoPHP\Inbox\InboxSettingGroup;
use CattoPHP\Inbox\InboxSettings;
use CattoPHP\Inbox\InboxStore;
use CattoPHP\Inbox\MailerReplySender;
use CattoPHP\Inbox\ReplySender;
use CattoPHP\Installation\Installation;
use CattoPHP\Links\DbalLinkStore;
use CattoPHP\Links\FileLinkStore;
use CattoPHP\Links\LinkStore;
use CattoPHP\Mail\Mailer;
use CattoPHP\Mail\TestEmailCommand;
use CattoPHP\Mailing\CampaignProject;
use CattoPHP\Mailing\Campaigns;
use CattoPHP\Mailing\CampaignSender;
use CattoPHP\Mailing\CattoMail\CattoMailClient;
use CattoPHP\Mailing\CattoMail\CattoMailConnection;
use CattoPHP\Mailing\CattoMail\CattoMailEventHandler;
use CattoPHP\Mailing\CattoMail\CattoMailEvents;
use CattoPHP\Mailing\CattoMail\CattoMailTransport;
use CattoPHP\Mailing\CattoMail\CattoMailWebhook;
use CattoPHP\Mailing\CattoMail\CopyEvents;
use CattoPHP\Mailing\Consents;
use CattoPHP\Mailing\Copies;
use CattoPHP\Mailing\CopyLinks;
use CattoPHP\Mailing\CopyOutcomes;
use CattoPHP\Mailing\Forwards;
use CattoPHP\Mailing\Links;
use CattoPHP\Mailing\MailingFigures;
use CattoPHP\Mailing\MailingLists;
use CattoPHP\Mailing\MailingSettingGroup;
use CattoPHP\Mailing\MailingSettings;
use CattoPHP\Mailing\MailingTransports;
use CattoPHP\Mailing\RecipientAddresses;
use CattoPHP\Mailing\SmtpMailingTransport;
use CattoPHP\Mailing\Subscriptions;
use CattoPHP\Mailing\Suppressions;
use CattoPHP\Module\Modules;
use CattoPHP\Module\ModulesCommand;
use CattoPHP\Network\GeoIp;
use CattoPHP\Network\GeoIpNetworkLookup;
use CattoPHP\Network\NetworkLookup;
use CattoPHP\Network\NoNetworkLookup;
use CattoPHP\Network\ReputationLists;
use CattoPHP\Payments\GatewayDefinitions;
use CattoPHP\Payments\PaymentGateways;
use CattoPHP\Payments\PaymentSettings;
use CattoPHP\Protection\Flags;
use CattoPHP\Protection\FormGuard;
use CattoPHP\Protection\LockoutGate;
use CattoPHP\Protection\LockoutListener;
use CattoPHP\Protection\Lockouts;
use CattoPHP\Protection\LockoutService;
use CattoPHP\Protection\PathMask;
use CattoPHP\Protection\ProtectionRetention;
use CattoPHP\Protection\ProtectionRules;
use CattoPHP\Protection\ProtectionSettingGroup;
use CattoPHP\Protection\ProtectionSettings;
use CattoPHP\Protection\RefusedEmails;
use CattoPHP\Protection\RequestRecorder;
use CattoPHP\Protection\SearchEngineProof;
use CattoPHP\Protection\ServerLink;
use CattoPHP\Protection\SignedInVisitors;
use CattoPHP\Protection\SiteLog;
use CattoPHP\Protection\SiteProtectionTask;
use CattoPHP\Protection\VisitorAccounts;
use CattoPHP\Runtime\SiteMode;
use CattoPHP\Scheduler\DbalRunStore;
use CattoPHP\Scheduler\FileRunStore;
use CattoPHP\Scheduler\LogArchive;
use CattoPHP\Scheduler\LogArchiveSettings;
use CattoPHP\Scheduler\LogArchiveTask;
use CattoPHP\Scheduler\RunStore;
use CattoPHP\Scheduler\ScheduledTask;
use CattoPHP\Scheduler\Scheduler;
use CattoPHP\Scheduler\ScheduleRunCommand;
use CattoPHP\Scheduler\TaskStatus;
use CattoPHP\Schema\MigrateCommand;
use CattoPHP\Schema\SchemaRunner;
use CattoPHP\Schema\SchemaStep;
use CattoPHP\Search\DefaultSharePicture;
use CattoPHP\Search\LlmsTxt;
use CattoPHP\Search\LlmsTxtContent;
use CattoPHP\Search\PageHead;
use CattoPHP\Search\PageOverrides;
use CattoPHP\Search\PublicPageProvider;
use CattoPHP\Search\PublicPages;
use CattoPHP\Search\RobotsTxt;
use CattoPHP\Search\SearchSettingGroup;
use CattoPHP\Search\SearchSettings;
use CattoPHP\Search\SharePictures;
use CattoPHP\Search\Sitemap;
use CattoPHP\Search\SitemapSource;
use CattoPHP\Security\KeyedHash;
use CattoPHP\Security\SecretCipher;
use CattoPHP\Security\TwoStep\DbalTwoStepStore;
use CattoPHP\Security\TwoStep\FileTwoStepStore;
use CattoPHP\Security\TwoStep\TwoStep;
use CattoPHP\Security\TwoStep\TwoStepStore;
use CattoPHP\Sessions\DbalSessionStore;
use CattoPHP\Sessions\FileSessionStore;
use CattoPHP\Sessions\SessionStore;
use CattoPHP\Settings\DbalSettingsStore;
use CattoPHP\Settings\EnvironmentValues;
use CattoPHP\Settings\FoundationSettings;
use CattoPHP\Settings\MemorySettingsStore;
use CattoPHP\Settings\Settings;
use CattoPHP\Settings\SettingsCatalogue;
use CattoPHP\Settings\SettingsProvider;
use CattoPHP\Settings\SettingsStore;
use CattoPHP\Setup\DbalSetupMarkStore;
use CattoPHP\Setup\SetupChecklist;
use CattoPHP\Setup\SetupMarkStore;
use CattoPHP\Setup\SetupStepProvider;
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
use CattoPHP\Signup\MailDomainCheck;
use CattoPHP\Signup\SignupAttempts;
use CattoPHP\Signup\SignupChecks;
use CattoPHP\Signup\SignupCountries;
use CattoPHP\Signup\SignupListsTask;
use CattoPHP\Signup\SignupSettingGroup;
use CattoPHP\Telegram\OwnerMessages;
use CattoPHP\Telegram\OwnerMessageSender;
use CattoPHP\Telegram\TelegramAccounts;
use CattoPHP\Telegram\TelegramBot;
use CattoPHP\Telegram\TelegramClient;
use CattoPHP\Telegram\TelegramConnections;
use CattoPHP\Telegram\TelegramDeliveryListener;
use CattoPHP\Telegram\TelegramInbox;
use CattoPHP\Telegram\TelegramLog;
use CattoPHP\Telegram\TelegramMessenger;
use CattoPHP\Telegram\TelegramSetup;
use CattoPHP\Telegram\TelegramState;
use CattoPHP\Telegram\TelegramTask;
use CattoPHP\Telegram\TelegramWords;
use CattoPHP\Theme\InstallThemesCommand;
use CattoPHP\Theme\MemoryThemeChoice;
use CattoPHP\Theme\PackageValidator;
use CattoPHP\Theme\PageRenderer;
use CattoPHP\Theme\Publisher;
use CattoPHP\Theme\Templates;
use CattoPHP\Theme\ThemeManager;
use CattoPHP\Twig\CattoPHPExtension;
use CattoPHP\Twig\UiExtension;
use CattoPHP\Ui\Components;
use CattoPHP\Ui\ComponentSet;
use CattoPHP\Ui\DrawingCheck;
use CattoPHP\Ui\IconLibrary;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;
use InvalidArgumentException;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\HttpKernel\KernelEvents;

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
                ->arrayNode('scheduler')->canBeEnabled()->children()
                    ->scalarNode('command_alias')->defaultNull()->info('Another name for cattophp:schedule:run, such as the one a project\'s cron line or worker already runs')->end()
                    ->arrayNode('log_archive')->canBeEnabled()->children()
                        ->scalarNode('folder')->defaultNull()->info('The folder the logs are in: each .log there is archived just after midnight')->end()
                        ->scalarNode('first')->defaultValue('application.log')->info('The project\'s main log, listed first')->end()
                    ->end()->end()
                ->end()->end()
                ->arrayNode('network')->addDefaultsIfNotSet()->info('What an address says about a visitor: its country, network and lists, for site protection and the sign-up check')->children()
                    ->scalarNode('lookup')->defaultNull()->info('A project\'s own service that tells an address\'s country and network (CattoPHP\Network\NetworkLookup), in place of CattoPHP\'s')->end()
                    ->scalarNode('country_database')->defaultValue('')->info('A MaxMind country or city database\'s full path, such as GeoLite2-City.mmdb, read through Geocoder\'s GeoIP2 provider (needs geocoder-php/geoip2-provider)')->end()
                    ->scalarNode('geocoder')->defaultNull()->info('A Geocoder provider\'s service id (Geocoder\Provider\Provider), such as a web service or a chain of providers, in place of the GeoIP2 provider over the country database')->end()
                    ->scalarNode('asn_database')->defaultNull()->info('The GeoLite2 ASN database\'s full path; by default GeoLite2-ASN.mmdb beside the country database')->end()
                    ->scalarNode('lists_folder')->defaultNull()->info('Where the Tor, VPN, hosting and throwaway email lists are kept, fetched by the signup_lists task (needs symfony/http-client)')->end()
                    ->scalarNode('http_client')->defaultValue('http_client')->info('The HTTP client\'s service id')->end()
                ->end()->end()
                ->arrayNode('signup')->canBeEnabled()->info('The sign-up check: each registration judged and recorded, the allowed and blocked countries (needs the database)')->children()
                    ->integerNode('task_priority')->defaultValue(0)->info('The signup_lists task\'s place among the scheduled tasks')->end()
                ->end()->end()
                ->arrayNode('identity')->canBeEnabled()->info('Identity verification on Didit\'s hosted page, its keys in .env (needs the database and symfony/http-client)')->children()
                    ->scalarNode('return_path')->defaultValue('/identity/return')->info('Where a person comes back to from Didit\'s page: the project\'s route')->end()
                    ->scalarNode('webhook_path')->defaultValue('/webhooks/didit')->info('Where Didit sends its webhooks: the project\'s route')->end()
                    ->scalarNode('accounts')->defaultNull()->info('The service that gives an account\'s reference for Didit (CattoPHP\Identity\IdentityAccounts); the account id by default')->end()
                    ->scalarNode('approved')->defaultNull()->info('The service told when an account\'s identity is approved (CattoPHP\Identity\IdentityApproved)')->end()
                    ->scalarNode('account_detail')->defaultValue('account')->info('The name an account goes by in the activity log\'s details')->end()
                    ->integerNode('minimum_age')->defaultNull()->info('The age from which an approval is passed on to the approved service; any age when not given')->end()
                    ->scalarNode('http_client')->defaultValue('http_client')->info('The HTTP client\'s service id')->end()
                ->end()->end()
                ->arrayNode('inbox')->canBeEnabled()->info('The Inbox: the contact form\'s messages as conversations, the spam check, folders, replies and answers from a mailbox (needs the database)')->children()
                    ->scalarNode('reference_prefix')->defaultValue('MSG-')->info('What a conversation\'s reference starts with, its number after it')->end()
                    ->scalarNode('files_folder')->defaultValue('%kernel.project_dir%/var')->info('Where a reply\'s files are kept, each under inbox/<its public id>/')->end()
                    ->scalarNode('documents')->defaultNull()->info('The service that gives an account\'s documents a reply may attach (CattoPHP\Inbox\InboxDocuments)')->end()
                    ->scalarNode('sender')->defaultNull()->info('The service that sends a reply (CattoPHP\Inbox\ReplySender); CattoPHP\'s mailer by default')->end()
                    ->integerNode('task_priority')->defaultValue(0)->info('The inbox_mailbox task\'s place among the scheduled tasks')->end()
                ->end()->end()
                ->arrayNode('mailing')->canBeEnabled()->info('Mailing: the sender and transport settings, a mail server or catto-mail (its connection, events and send jobs) (needs the database)')->children()
                    ->scalarNode('project')->defaultValue('cattophp')->info('The project\'s short name in Feedback-ID headers and catto-mail\'s job references, such as example')->end()
                    ->scalarNode('callback_path')->defaultValue('/mailing/cattomail/callback')->info('The path catto-mail\'s connect page returns to with its approval')->end()
                    ->scalarNode('webhook_path')->defaultValue('/webhooks/cattomail')->info('The path catto-mail sends its events to')->end()
                    ->scalarNode('campaign_project')->defaultNull()->info('The service that says who a campaign goes to, who it comes from and how a copy is drawn (CattoPHP\\Mailing\\CampaignProject); without it campaigns are kept but not sent')->end()
                    ->scalarNode('recipient_addresses')->defaultNull()->info('The service that says where a mail server delivers an address (CattoPHP\Mailing\RecipientAddresses); every address its own by default')->end()
                    ->scalarNode('http_client')->defaultValue('http_client')->info('The HTTP client\'s service id')->end()
                ->end()->end()
                ->arrayNode('protection')->canBeEnabled()->info('Site protection: the Site Log, flags, time-outs and lockouts, the rules, the form guard and the link to a server firewall (needs the database)')->children()
                    ->arrayNode('secret_paths')->info('Path prefixes whose next part is always a secret, such as an unsubscribe link\'s token: \'/m/[a-z]/\', \'/join/\'')->scalarPrototype()->end()->defaultValue([])->end()
                    ->scalarNode('firewall_folder')->defaultValue('%kernel.project_dir%/var/server-firewall')->info('The folder of the two files shared with a firewall on the server')->end()
                    ->scalarNode('visitors')->defaultNull()->info('The service that tells who is signed in (CattoPHP\Protection\VisitorAccounts); CattoPHP\'s sign-in by default')->end()
                    ->scalarNode('administrator_permission')->defaultValue('PLATFORM.SETTINGS.VIEW')->info('With the default visitors: the permission that makes someone an administrator, whose addresses the rules spare')->end()
                    ->booleanNode('gate_listener')->defaultTrue()->info('The lockout gate as Symfony\'s first request listener; off for a project that calls it before its own session')->end()
                    ->booleanNode('record_requests')->defaultTrue()->info('Write each request into the Site Log as it ends')->end()
                    ->integerNode('task_priority')->defaultValue(0)->info('The site_protection task\'s place among the scheduled tasks')->end()
                    ->arrayNode('forms')->info('Each guarded form\'s name and its words')->useAttributeAsKey('name')->scalarPrototype()->end()
                        ->defaultValue([FormGuard::SIGN_IN => 'Sign in', FormGuard::REGISTER => 'Register', FormGuard::CONTACT => 'Contact'])->end()
                    ->arrayNode('list_only_forms')->info('The forms only the refused list refuses (one with its own sign-up check)')->scalarPrototype()->end()->defaultValue([FormGuard::REGISTER])->end()
                ->end()->end()
                ->arrayNode('telegram')->canBeEnabled()->info('A Telegram bot: messages to the owner, messages to people, their connected accounts (needs the database and symfony/http-client)')->children()
                    ->arrayNode('choices')->info('The project\'s own choices of what sends the owner a message, such as on_sale, each on until turned off')->scalarPrototype()->end()->defaultValue([])->end()
                    ->scalarNode('http_client')->defaultValue('http_client')->info('The HTTP client\'s service id')->end()
                    ->integerNode('task_priority')->defaultValue(0)->info('The owner_messages task\'s place among the scheduled tasks')->end()
                ->end()->end()
                ->arrayNode('api')->canBeEnabled()->info('An API: tokens issued to accounts with scopes, Bearer sign-in, the error document (needs the database)')->children()
                    ->scalarNode('path')->defaultValue('/api/')->info('Where the API is: its exceptions are answered in its error document')->end()
                    ->scalarNode('token_prefix')->defaultValue('cat_')->info('What a token starts with, so it can be recognised')->end()
                    ->arrayNode('scopes')->info('Each scope the project offers => what it allows')->useAttributeAsKey('scope')->normalizeKeys(false)->scalarPrototype()->end()->end()
                    ->scalarNode('all_scopes')->defaultNull()->info('The scope that stands for every scope, if any')->end()
                    ->scalarNode('accounts')->defaultNull()->info('The service that says whether an account may use the API and what its identity reports (CattoPHP\Api\ApiAccounts)')->end()
                ->end()->end()
                ->arrayNode('documents')->canBeEnabled()->info('Documents and PDFs: templates in the controlled language, one renderer, PDFs through Dompdf (needs dompdf/dompdf)')->children()
                    ->scalarNode('fonts')->defaultValue('')->info('The folder of the project\'s fonts, each family in four faces: <File>-Regular.ttf, -Bold, -Italic and -BoldItalic')->end()
                    ->arrayNode('families')->info('Each font family\'s name in CSS => its files\' prefix in that folder')->useAttributeAsKey('family')->normalizeKeys(false)->scalarPrototype()->end()->end()
                    ->scalarNode('font_cache')->defaultValue('%kernel.project_dir%/var/dompdf-fonts')->info('Where Dompdf keeps its font metrics: a writable folder')->end()
                    ->scalarNode('images')->defaultNull()->info('The service that gives a stored picture named asset:<sha256> (CattoPHP\Document\DocumentImages)')->end()
                ->end()->end()
                ->arrayNode('search')->canBeEnabled()->info('Search engines and AI: robots.txt, the sitemap, llms.txt, share pictures, structured data and the heads of public pages (needs the database)')->children()
                    ->arrayNode('private_paths')->info('The paths crawlers are asked to leave alone, in groups, each with the reason written above it')->arrayPrototype()->children()
                        ->scalarNode('why')->isRequired()->cannotBeEmpty()->end()
                        ->arrayNode('paths')->isRequired()->requiresAtLeastOneElement()->scalarPrototype()->end()->end()
                    ->end()->end()->end()
                    ->scalarNode('share_pictures')->defaultValue('%kernel.project_dir%/var/share-pictures')->info('The folder share pictures are drawn into')->end()
                    ->scalarNode('rsvg_convert')->defaultNull()->info('rsvg-convert\'s full path; looked for in the usual places when not given')->end()
                    ->scalarNode('llms_txt')->defaultNull()->info('The service that says what /llms.txt holds (CattoPHP\Search\LlmsTxtContent); without one there is no llms.txt')->end()
                    ->scalarNode('site_picture')->defaultNull()->info('The service that gives the picture a page without its own is shared with (CattoPHP\Search\DefaultSharePicture)')->end()
                    ->scalarNode('cache')->defaultValue('cache.app')->info('The cache a rendered sitemap is kept in for an hour')->end()
                ->end()->end()
                ->arrayNode('setup')->canBeEnabled()->info('The setup checklist: the steps a new installation needs, supplied by the project\'s SetupStepProvider services')->end()
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

        // The optional modules switched on, and what stops any of them working, said plainly before anything is built.
        $on = [];
        foreach (array_keys(Modules::all()) as $key) {
            $section = $config[$key] ?? null;
            if (is_array($section) && ($section['enabled'] ?? false) === true) {
                $on[] = $key;
            }
        }
        /** @var array{lookup:?string,country_database:string,geocoder:?string} $networkConfig */
        $networkConfig = $config['network'];
        $problems = Modules::problems($on, $database);
        if (($networkConfig['lookup'] === null || $networkConfig['lookup'] === '') && ($networkConfig['country_database'] !== '' || ($networkConfig['geocoder'] ?? null) !== null)
            && !interface_exists('Geocoder\Provider\Provider')) {
            $problems[] = 'The network lookup (cattophp.network) reads places through Geocoder and needs geocoder-php/geoip2-provider: run composer require geocoder-php/geoip2-provider.';
        }
        if ($problems !== []) {
            throw new InvalidArgumentException("CattoPHP's modules cannot start:\n- " . implode("\n- ", $problems));
        }

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
        $services->set(FormStamp::class)->args([param('cattophp.application_key')])->public();
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
            // The foundations' tables, and those of the modules switched on.
            foreach (Modules::schemaSteps($on) as $step) {
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
        $services->set(ModulesCommand::class)->args([$on, $database, $database ? service($storage['connection']) : null])->tag('console.command');
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
        // The scheduler, for a project with background work: its tasks are the services that implement ScheduledTask.
        /** @var array{enabled:bool,command_alias:?string,log_archive:array{enabled:bool,folder:?string,first:string}} $scheduler */
        $scheduler = $config['scheduler'];
        if ($scheduler['enabled']) {
            $builder->registerForAutoconfiguration(ScheduledTask::class)->addTag(ScheduledTask::TAG);
            if ($database) {
                $services->set(RunStore::class, DbalRunStore::class)->args([service($storage['connection'])]);
            } else {
                $services->set(RunStore::class, FileRunStore::class)->args([$folder . '/scheduled-jobs.json']);
            }
            $logArchive = $scheduler['log_archive'];
            if ($logArchive['enabled']) {
                if (trim((string) $logArchive['folder']) === '') throw new InvalidArgumentException('cattophp.scheduler.log_archive.folder must name the folder the logs are in.');
                $services->set(LogArchive::class)->args([rtrim((string) $logArchive['folder'], '/'), service(Settings::class), $logArchive['first']])->public();
                $services->set(LogArchiveTask::class)->args([service(LogArchive::class)])->tag(ScheduledTask::TAG);
                $services->set(LogArchiveSettings::class)->tag(SettingsProvider::TAG);
            }
            $services->set(Scheduler::class)->args([tagged_iterator(ScheduledTask::TAG), service(RunStore::class), service('clock')])->public();
            $services->set(TaskStatus::class)->args([service(Scheduler::class), service(RunStore::class), service('clock')])->public();
            $alias = trim((string) ($scheduler['command_alias'] ?? ''));
            $services->set(ScheduleRunCommand::class)->args([service(Scheduler::class)])
                ->tag('console.command', $alias === '' ? [] : ['command' => 'cattophp:schedule:run|' . $alias]);
        }

        // The setup checklist, for a project with steps a new installation needs: its steps come from the services that
        // implement SetupStepProvider, and what people mark is kept in the database.
        /** @var array{enabled:bool} $setup */
        $setup = $config['setup'];
        if ($setup['enabled']) {
            $builder->registerForAutoconfiguration(SetupStepProvider::class)->addTag(SetupStepProvider::TAG);
            $services->set(SetupMarkStore::class, DbalSetupMarkStore::class)->args([service($storage['connection'])]);
            $services->set(SetupChecklist::class)->args([tagged_iterator(SetupStepProvider::TAG), service(SetupMarkStore::class), service('clock'), service(ActivityLog::class)])->public();
        }

        // What an address says about a visitor, for site protection and the sign-up check: CattoPHP's lookup (its country and
        // place through Geocoder, over a MaxMind database or a provider the project names) and the published lists, a project's
        // own, or only whether an address is private.
        /** @var array{lookup:?string,country_database:string,geocoder:?string,asn_database:?string,lists_folder:?string,http_client:string} $network */
        $network = $config['network'];
        $lists = trim((string) $network['lists_folder']) !== '';
        if ($lists) {
            $services->set(ReputationLists::class)->args([rtrim((string) $network['lists_folder'], '/'), service($network['http_client'])->nullOnInvalid()])->public();
        }
        $geocoder = $network['geocoder'] !== null && $network['geocoder'] !== '';
        $services->set(GeoIp::class)->args([(string) $network['country_database'], $geocoder ? service((string) $network['geocoder']) : null])->public();
        if ($network['lookup'] !== null && $network['lookup'] !== '') {
            $services->alias(NetworkLookup::class, $network['lookup'])->public();
        } elseif ($network['country_database'] !== '' || $geocoder) {
            $services->set(GeoIpNetworkLookup::class)->args([service(GeoIp::class), $lists ? service(ReputationLists::class) : null, $network['asn_database']])->public();
            $services->alias(NetworkLookup::class, GeoIpNetworkLookup::class)->public();
        } else {
            $services->set(NetworkLookup::class, NoNetworkLookup::class)->public();
        }

        // The sign-up check, for a project where people register: each registration judged against the country lists and
        // recorded with its outcome.
        /** @var array{enabled:bool,task_priority:int} $signup */
        $signup = $config['signup'];
        if ($signup['enabled']) {
            $services->set(SignupSettingGroup::class)->tag(SettingsProvider::TAG);
            $services->set(SignupCountries::class)->args([service(Settings::class), service(EnvironmentValues::class)])->public();
            $services->set(SignupAttempts::class)->args([service($storage['connection'])])->public();
            $services->set(MailDomainCheck::class);
            $services->set(SignupChecks::class)->args([service(SignupAttempts::class), service(SignupCountries::class), param('cattophp.application_key'), service(NetworkLookup::class),
                $lists ? service(ReputationLists::class) : null, service(MailDomainCheck::class), service('request_stack')->nullOnInvalid()])->public();
            if ($scheduler['enabled'] && $lists) {
                $services->set(SignupListsTask::class)->args([service(ReputationLists::class)])->tag(ScheduledTask::TAG, ['priority' => $signup['task_priority']]);
            }
        }

        // Identity verification, for a project that must know who someone is: Didit's hosted page, its result by webhook or
        // on the person's return, and the checks kept in the database.
        /** @var array{enabled:bool,return_path:string,webhook_path:string,accounts:?string,approved:?string,account_detail:string,minimum_age:?int,http_client:string} $identity */
        $identity = $config['identity'];
        if ($identity['enabled']) {
            $services->set(IdentitySettings::class)->args([service(EnvironmentValues::class), service(SiteMode::class), service(Installation::class), $identity['return_path'], $identity['webhook_path']])->public();
            $services->set(DiditClient::class)->args([service($identity['http_client']), service(IdentitySettings::class)])->public();
            $services->set(IdentityChecks::class)->args([service($storage['connection'])])->public();
            $identityAccounts = $identity['accounts'] !== null && $identity['accounts'] !== '';
            if ($identityAccounts) {
                $services->alias(IdentityAccounts::class, (string) $identity['accounts']);
            }
            $approved = $identity['approved'] !== null && $identity['approved'] !== '';
            if ($approved) {
                $services->alias(IdentityApproved::class, (string) $identity['approved']);
            }
            $services->set(IdentityVerification::class)->args([service(IdentityChecks::class), service(DiditClient::class), service(IdentitySettings::class), service('clock'), service(ActivityLog::class),
                $identityAccounts ? service(IdentityAccounts::class) : null, $approved ? service(IdentityApproved::class) : null, $identity['account_detail'], $identity['minimum_age']])->public();
        }

        // The Inbox, for a project with a contact form: each message a conversation, judged by the spam check, in folders,
        // answered by replies and by answers read from a mailbox, its records in the database.
        /** @var array{enabled:bool,reference_prefix:string,files_folder:string,documents:?string,sender:?string,task_priority:int} $inbox */
        $inbox = $config['inbox'];
        if ($inbox['enabled']) {
            if (!$signup['enabled']) {
                // The spam check reads the blocked countries and whether a domain receives mail, as the sign-up check does.
                $services->set(SignupCountries::class)->args([service(Settings::class), service(EnvironmentValues::class)])->public();
                $services->set(MailDomainCheck::class);
            }
            $services->set(InboxSettingGroup::class)->tag(SettingsProvider::TAG);
            $services->set(InboxSettings::class)->args([service(Settings::class)])->public();
            $services->set(InboxStore::class)->args([service($storage['connection']), $inbox['reference_prefix']])->public();
            $services->set(Inbox::class)->args([service(InboxStore::class), service(InboxSettings::class), service(FormStamp::class), service(NetworkLookup::class),
                $lists ? service(ReputationLists::class) : null, service(MailDomainCheck::class), service(SignupCountries::class), service(ActivityLog::class),
                service('request_stack')->nullOnInvalid()])->public();
            if ($inbox['sender'] !== null && $inbox['sender'] !== '') {
                $services->alias(ReplySender::class, (string) $inbox['sender']);
            } else {
                $services->set(ReplySender::class, MailerReplySender::class)->args([service(Mailer::class)]);
            }
            $documentsGiven = $inbox['documents'] !== null && $inbox['documents'] !== '';
            if ($documentsGiven) {
                $services->alias(InboxDocuments::class, (string) $inbox['documents']);
            }
            $services->set(InboxReplies::class)->args([service(InboxStore::class), service(InboxSettings::class), service(Settings::class), service(Installation::class),
                service(ReplySender::class), rtrim($inbox['files_folder'], '/'), $documentsGiven ? service(InboxDocuments::class) : null, service(ActivityLog::class),
                service(EnvironmentValues::class)])->public();
            $services->set(ImapMailbox::class)->public();
            $services->set(InboxMailbox::class)->args([service(InboxSettings::class), service(InboxStore::class), service(ImapMailbox::class)])->public();
            if ($scheduler['enabled']) {
                $services->set(InboxMailboxTask::class)->args([service(InboxMailbox::class)])->tag(ScheduledTask::TAG, ['priority' => $inbox['task_priority']]);
            }
        }

        // Mailing: where news is sent from and through (Settings > Mailing), a mail server or catto-mail, with catto-mail's
        // connection, the events its webhook reports (worked through by the project's handlers) and its send jobs.
        /** @var array{enabled:bool,project:string,callback_path:string,webhook_path:string,campaign_project:?string,recipient_addresses:?string,http_client:string} $mailing */
        $mailing = $config['mailing'];
        if ($mailing['enabled']) {
            $builder->registerForAutoconfiguration(CattoMailEventHandler::class)->addTag(CattoMailEventHandler::TAG);
            $services->set(MailingSettingGroup::class)->tag(SettingsProvider::TAG);
            $services->set(MailingSettings::class)->args([service(Settings::class)])->public();
            $services->set(CattoMailClient::class)->args([service($mailing['http_client']), service(MailingSettings::class)])->public();
            $services->set(CattoMailConnection::class)->args([service(MailingSettings::class), service(CattoMailClient::class), service($storage['connection']), service(Settings::class),
                service(Installation::class), service('clock'), $mailing['project'], $mailing['callback_path'], $mailing['webhook_path'], service(ActivityLog::class)])->public();
            $services->set(CattoMailEvents::class)->args([service($storage['connection']), tagged_iterator(CattoMailEventHandler::TAG)])->public();
            $services->set(CattoMailWebhook::class)->args([service(MailingSettings::class), service(CattoMailEvents::class), service('clock')])->public();
            $addresses = $mailing['recipient_addresses'] !== null && $mailing['recipient_addresses'] !== '';
            if ($addresses) {
                $services->alias(RecipientAddresses::class, (string) $mailing['recipient_addresses']);
            }
            // Every argument given: autowiring would hand the last one, a mail server for tests, the project's own mailer transport.
            $services->set(SmtpMailingTransport::class)->args([service(MailingSettings::class), service(Settings::class), $mailing['project'], $addresses ? service(RecipientAddresses::class) : null, null])->public();
            $services->set(CattoMailTransport::class)->args([service(CattoMailClient::class), $mailing['project'], service(CattoMailConnection::class)])->public();
            $services->set(MailingTransports::class)->args([service(MailingSettings::class), service(SmtpMailingTransport::class), service(CattoMailTransport::class)])->public();
            // The lists, each person's answers and their history, and the addresses never emailed.
            $services->set(MailingLists::class)->args([service($storage['connection'])])->public();
            $services->set(Subscriptions::class)->args([service($storage['connection'])])->public();
            $services->set(Suppressions::class)->args([service($storage['connection'])])->public();
            $services->set(Consents::class)->args([service($storage['connection']), service(MailingLists::class), service(Subscriptions::class), service(ActivityLog::class)])->public();
            // Campaigns, each person's copy and what became of it, the links in a campaign; catto-mail's events applied to
            // the copies; and the sender, once the project says how its campaigns are sent.
            $services->set(Campaigns::class)->args([service($storage['connection'])])->public();
            $services->set(Copies::class)->args([service($storage['connection'])])->public();
            $services->set(Links::class)->args([service($storage['connection'])])->public();
            $services->set(MailingFigures::class)->args([service($storage['connection'])])->public();
            $services->set(CopyOutcomes::class)->args([service(Copies::class), service(Suppressions::class), service(Consents::class), service(ActivityLog::class)])->public();
            $services->set(CopyEvents::class)->args([service(Copies::class), service(CopyOutcomes::class)])->tag(CattoMailEventHandler::TAG);
            if ($mailing['campaign_project'] !== null && $mailing['campaign_project'] !== '') {
                $services->alias(CampaignProject::class, (string) $mailing['campaign_project']);
                $services->set(CampaignSender::class)->args([service(Campaigns::class), service(Copies::class), service(MailingTransports::class), service(CopyOutcomes::class),
                    service(Subscriptions::class), service(Suppressions::class), service(CampaignProject::class), service($storage['connection']), service('clock')])->public();
                // What the links in a copy do, and Forward to a friend (the friend's copy drawn by the project).
                $services->set(CopyLinks::class)->args([service(Copies::class), service(Links::class), service(Campaigns::class), service(Subscriptions::class), service(CampaignProject::class)])->public();
                $services->set(Forwards::class)->args([service($storage['connection']), service(Suppressions::class), service(Mailer::class), service(CampaignProject::class), service(ActivityLog::class)])->public();
            }
        }

        // Site protection, for a public site: the Site Log, flags, time-outs and lockouts, the rules every minute, the form
        // guard and the link to a server firewall, its records in the database.
        /** @var array{enabled:bool,secret_paths:list<string>,firewall_folder:string,visitors:?string,administrator_permission:string,gate_listener:bool,record_requests:bool,task_priority:int,forms:array<string,string>,list_only_forms:list<string>} $protection */
        $protection = $config['protection'];
        if ($protection['enabled']) {
            $connection = service($storage['connection']);
            $services->set(PathMask::class)->args([$protection['secret_paths']]);
            $services->set(ServerLink::class)->args([rtrim($protection['firewall_folder'], '/'), service(PathMask::class)])->public();
            foreach ([SiteLog::class, Flags::class, Lockouts::class, RefusedEmails::class] as $store) {
                $services->set($store)->args([$connection])->public();
            }
            $services->set(ProtectionSettingGroup::class)->tag(SettingsProvider::TAG);
            $services->set(ProtectionSettings::class)->args([service(Settings::class)])->public();
            if ($protection['visitors'] !== null && $protection['visitors'] !== '') {
                $services->alias(VisitorAccounts::class, $protection['visitors']);
            } else {
                $services->set(VisitorAccounts::class, SignedInVisitors::class)->args([service('security.token_storage')->nullOnInvalid(), service('security.authorization_checker')->nullOnInvalid(), $protection['administrator_permission']]);
            }
            $services->set(SearchEngineProof::class);
            $services->set(ProtectionRules::class)->args([service(SiteLog::class), service(ProtectionSettings::class), service(Flags::class), service(Lockouts::class), service(ServerLink::class), service(SearchEngineProof::class)])->public();
            $services->set(LockoutService::class)->args([service(Lockouts::class), service(Flags::class), service(ProtectionSettings::class), service(ServerLink::class), service(NetworkLookup::class), service(ActivityLog::class), service('request_stack')->nullOnInvalid()])->public();
            $services->set(ProtectionRetention::class)->args([service(ProtectionSettings::class), service(SiteLog::class), service(Flags::class), service(Lockouts::class)])->public();
            $services->set(FormGuard::class)->args([service('request_stack'), service(RefusedEmails::class), service(ProtectionSettings::class), param('cattophp.application_key'), $protection['forms'], $protection['list_only_forms']])->public();
            $services->set(LockoutGate::class)->args([service(Lockouts::class), service(SiteLog::class), service(NetworkLookup::class), service(PathMask::class), service(ProtectionSettings::class)])->public();
            if ($protection['gate_listener']) {
                $services->set(LockoutListener::class)->args([service(LockoutGate::class)])->tag('kernel.event_listener', ['event' => 'kernel.request', 'priority' => LockoutListener::PRIORITY]);
            }
            if ($protection['record_requests']) {
                $services->set(RequestRecorder::class)->args([service(SiteLog::class), service(VisitorAccounts::class), service(NetworkLookup::class), service(PathMask::class), param('kernel.environment')])
                    ->tag('kernel.event_listener', ['event' => 'kernel.terminate']);
            }
            if ($scheduler['enabled']) {
                $services->set(SiteProtectionTask::class)->args([service(ProtectionRules::class)])->tag(ScheduledTask::TAG, ['priority' => $protection['task_priority']]);
            }
        }

        // A Telegram bot, for a project that messages its owner or lets people connect Telegram: its records in the database.
        /** @var array{enabled:bool,choices:list<string>,http_client:string,task_priority:int} $telegram */
        $telegram = $config['telegram'];
        if ($telegram['enabled']) {
            $connection = service($storage['connection']);
            $services->set(TelegramState::class)->args([$connection]);
            $services->set(TelegramLog::class)->args([$connection])->public();
            $services->set(TelegramAccounts::class)->args([$connection])->public();
            $services->set(TelegramBot::class)->args([service(TelegramState::class), service(SecretCipher::class)])->public();
            $services->set(TelegramWords::class)->args([service(SiteMode::class), service(Installation::class)])->public();
            $services->set(TelegramClient::class)->args([service($telegram['http_client'])])->public();
            $services->set(OwnerMessages::class)->args([service(TelegramBot::class), service(TelegramLog::class), service(TelegramWords::class)])->public();
            $services->set(TelegramMessenger::class)->args([service(TelegramBot::class), service(TelegramLog::class), service(TelegramClient::class)])->public();
            $services->set(TelegramDeliveryListener::class)->args([service(TelegramMessenger::class)])->tag('kernel.event_listener', ['event' => 'kernel.terminate']);
            $services->set(TelegramConnections::class)->args([service(LinkStore::class), service(TelegramAccounts::class), service(TelegramBot::class), service(TelegramMessenger::class), service(TelegramWords::class),
                $connection, service(SecretCipher::class), service('clock'), service(ActivityLog::class)])->public();
            $services->set(TelegramInbox::class)->args([service(TelegramBot::class), service(TelegramState::class), service(TelegramClient::class), service(TelegramAccounts::class), service(TelegramConnections::class),
                service(TelegramLog::class), service('clock')])->public();
            $services->set(OwnerMessageSender::class)->args([service(TelegramLog::class), service(TelegramBot::class), service(TelegramClient::class), service('clock'), service(TelegramInbox::class)])->public();
            $services->set(TelegramSetup::class)->args([service(TelegramBot::class), service(TelegramLog::class), service(TelegramWords::class), service(TelegramClient::class), service(TelegramInbox::class), $telegram['choices']])->public();
            if ($scheduler['enabled']) {
                $services->set(TelegramTask::class)->args([service(OwnerMessageSender::class)])->tag(ScheduledTask::TAG, ['priority' => $telegram['task_priority']]);
            }
        }

        // An API, for a project with one: tokens issued to accounts with scopes, the Bearer authenticator a project's API
        // firewall uses, the scope voter and the error document for exceptions under its path.
        /** @var array{enabled:bool,path:string,token_prefix:string,scopes:array<string,string>,all_scopes:?string,accounts:?string} $api */
        $api = $config['api'];
        if ($api['enabled']) {
            $accounts = $api['accounts'] !== null && $api['accounts'] !== '';
            if ($accounts) {
                $services->alias(ApiAccounts::class, (string) $api['accounts']);
            }
            $services->set(ApiTokens::class)->args([service($storage['connection']), service(Roles::class), $api['scopes'], $api['token_prefix'], $api['all_scopes'],
                $accounts ? service(ApiAccounts::class) : null, service(ActivityLog::class)])->public();
            $services->set(ApiAccess::class)->public();
            $services->set(ApiTokenAuthenticator::class)->args([service(ApiTokens::class), service(KeyedHash::class)])->public();
            $services->set(ApiScopeVoter::class)->args([service(ApiTokens::class)])->tag('security.voter');
            $services->set(ApiExceptionListener::class)->args([$api['path']])->tag('kernel.event_subscriber');
        }

        // Documents and PDFs, for a project that draws documents: the template language and its rules, one renderer, and
        // PDFs through Dompdf with the project's fonts.
        /** @var array{enabled:bool,fonts:string,families:array<string,string>,font_cache:string,images:?string} $documents */
        $documents = $config['documents'];
        if ($documents['enabled']) {
            $services->set(TemplateValidator::class)->public();
            $services->set(ValueFormatter::class)->public();
            $images = $documents['images'] !== null && $documents['images'] !== '';
            if ($images) {
                $services->alias(DocumentImages::class, (string) $documents['images']);
            }
            $services->set(DocumentRenderer::class)->args([service(TemplateValidator::class), service(ValueFormatter::class), $images ? service(DocumentImages::class) : null])->public();
            $services->set(PdfRenderer::class)->args([$documents['font_cache'], $documents['fonts'], $documents['families']])->public();
        }

        // Search engines and AI, for a project with public pages: robots.txt, the sitemap from the project's sources, llms.txt
        // from its content, share pictures, and what each public page's head needs; an administrator's own words for the
        // public pages are kept in the database.
        /** @var array{enabled:bool,private_paths:list<array{why:string,paths:list<string>}>,share_pictures:string,rsvg_convert:?string,llms_txt:?string,site_picture:?string,cache:string} $search */
        $search = $config['search'];
        if ($search['enabled']) {
            $builder->registerForAutoconfiguration(SitemapSource::class)->addTag(SitemapSource::TAG);
            $builder->registerForAutoconfiguration(PublicPageProvider::class)->addTag(PublicPageProvider::PAGES_TAG);
            $services->set(SearchSettingGroup::class)->tag(SettingsProvider::TAG);
            $services->set(SearchSettings::class)->args([service(Settings::class)])->public();
            $services->set(SharePictures::class)->args([$search['share_pictures'], $search['rsvg_convert']])->public();
            $services->set(Sitemap::class)->args([tagged_iterator(SitemapSource::TAG), service(SiteMode::class), service(Installation::class), service($search['cache'])])->public();
            $content = $search['llms_txt'] !== null && $search['llms_txt'] !== '';
            if ($content) {
                $services->alias(LlmsTxtContent::class, (string) $search['llms_txt']);
            }
            $services->set(LlmsTxt::class)->args([service(Sitemap::class), service(SiteMode::class), $content ? service(LlmsTxtContent::class) : null])->public();
            $services->set(RobotsTxt::class)->args([service(SearchSettings::class), service(Sitemap::class), service(LlmsTxt::class), $search['private_paths']])->public();
            $services->set(PublicPages::class)->args([tagged_iterator(PublicPageProvider::PAGES_TAG)])->public();
            $services->set(PageOverrides::class)->args([service($storage['connection']), service(PublicPages::class), service(ActivityLog::class)])->public();
            $picture = $search['site_picture'] !== null && $search['site_picture'] !== '';
            if ($picture) {
                $services->alias(DefaultSharePicture::class, (string) $search['site_picture']);
            }
            $services->set(PageHead::class)->args([service(PublicPages::class), service(Installation::class), service(EnvironmentValues::class), service(PageOverrides::class),
                $picture ? service(DefaultSharePicture::class) : null])->public();
        }

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
