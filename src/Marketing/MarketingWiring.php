<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use CattoPHP\Account\Records\MarketingRecords;
use CattoPHP\Activity\ActivityLog;
use CattoPHP\Http\ContentSecurityPolicy;
use CattoPHP\Installation\Installation;
use CattoPHP\Marketing\Journey\JourneyWiring;
use CattoPHP\Marketing\Landing\BuiltInTemplates;
use CattoPHP\Marketing\Landing\GenericLanding;
use CattoPHP\Marketing\Landing\Kinds\AudienceKind;
use CattoPHP\Marketing\Landing\Kinds\BenefitsKind;
use CattoPHP\Marketing\Landing\Kinds\CountdownKind;
use CattoPHP\Marketing\Landing\Kinds\CtaKind;
use CattoPHP\Marketing\Landing\Kinds\FaqKind;
use CattoPHP\Marketing\Landing\Kinds\HeroKind;
use CattoPHP\Marketing\Landing\Kinds\OffersKind;
use CattoPHP\Marketing\Landing\Kinds\OutcomesKind;
use CattoPHP\Marketing\Landing\Kinds\PictureKind;
use CattoPHP\Marketing\Landing\Kinds\VideoKind;
use CattoPHP\Marketing\Landing\Kinds\WordsKind;
use CattoPHP\Marketing\Landing\LandingPages;
use CattoPHP\Marketing\Landing\LandingPresses;
use CattoPHP\Marketing\Landing\LandingProject;
use CattoPHP\Marketing\Landing\LandingSections;
use CattoPHP\Marketing\Landing\LandingTemplates;
use CattoPHP\Marketing\Landing\LandingVersions;
use CattoPHP\Marketing\Landing\LandingViewBuilder;
use CattoPHP\Marketing\Landing\OfferPicture;
use CattoPHP\Marketing\Landing\PictureSources;
use CattoPHP\Marketing\Landing\SectionKinds;
use CattoPHP\Marketing\Landing\UploadedPictures;
use CattoPHP\Marketing\Mail\MailWiring;
use CattoPHP\Marketing\Pages\LandingPublicController;
use CattoPHP\Marketing\Pages\PagesWiring;
use CattoPHP\Marketing\Pages\TrackingLinkController;
use CattoPHP\Marketing\Sales\SalesWiring;
use CattoPHP\Marketing\Sites\SitesWiring;
use CattoPHP\Network\NetworkLookup;
use CattoPHP\ServiceTags;
use CattoPHP\Settings\Settings;
use CattoPHP\Theme\PageRenderer;
use CattoPHP\Ui\IconLibrary;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ReferenceConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * The Campaign Marketing Manager's services, wired by CattoPHPBundle when `cattophp.marketing` is on: campaigns, adverts,
 * publications and spend, the tracking links and the visitors and touches they record, the figures, landing pages, and
 * what the project sells and how it names things (MarketingProject). Each part of the module adds its own services in a
 * file of its own, called from register() in this order: the journey (Journey\JourneyWiring), a mailing message as a
 * publication (Mail\MailWiring), sales (Sales\SalesWiring), connected sites (Sites\SitesWiring) and the ready-made pages
 * (Pages\PagesWiring). Each is given this object: the module's configuration, the services, the container and its
 * builder, the database connection, and which of the scheduler and mailing are on.
 *
 * The bundle's services are private and autowired but never autoconfigured: tag a listener (kernel.event_listener or
 * kernel.event_subscriber), a scheduled task (ServiceTags::SCHEDULED_TASK, only while $scheduler) and a controller
 * (controller.service_arguments, public) yourself.
 */
final class MarketingWiring
{
    /**
     * @param array{enabled:bool,project:?string,files_folder:string,link_prefix:string,task_priority:int,landing_prefix:string,landing_project:?string,landing_builtin_templates:bool,returns:bool,returns_excluded:list<string>} $marketing
     */
    private function __construct(
        public readonly array $marketing,
        public readonly DefaultsConfigurator $services,
        public readonly ContainerConfigurator $container,
        public readonly ContainerBuilder $builder,
        private readonly string $connectionId,
        public readonly bool $scheduler,
        public readonly bool $mailing,
        public readonly bool $mailingCampaigns,
    ) {
    }

    /**
     * Wires the module: its configuration checked, its own services, then each part's.
     *
     * @param array{enabled:bool,project:?string,files_folder:string,link_prefix:string,task_priority:int,landing_prefix:string,landing_project:?string,landing_builtin_templates:bool,returns:bool,returns_excluded:list<string>} $marketing
     * @param string $connection the service id of the database connection (cattophp.storage.connection)
     * @param bool $mailingCampaigns whether mailing's campaigns are on too (cattophp.mailing with its campaign_project)
     */
    public static function register(array $marketing, DefaultsConfigurator $services, ContainerConfigurator $container, ContainerBuilder $builder, string $connection, bool $scheduler,
        bool $mailing, bool $mailingCampaigns): void
    {
        $wiring = new self($marketing, $services, $container, $builder, $connection, $scheduler, $mailing, $mailingCampaigns);
        $wiring->check();
        $wiring->core();
        JourneyWiring::register($wiring);
        MailWiring::register($wiring);
        SalesWiring::register($wiring);
        SitesWiring::register($wiring);
        PagesWiring::register($wiring);
    }

    /** The database connection, as an argument (a new reference each time, so changing one changes no other). */
    public function connection(): ReferenceConfigurator
    {
        return service($this->connectionId);
    }

    private function check(): void
    {
        $marketing = $this->marketing;
        if (preg_match(TrackingLinks::PREFIX, $marketing['link_prefix']) !== 1) {
            throw new InvalidArgumentException('cattophp.marketing.link_prefix is small letters, digits and hyphens, up to 20, such as c or go.');
        }
        if (preg_match(TrackingLinks::PREFIX, $marketing['landing_prefix']) !== 1 || $marketing['landing_prefix'] === $marketing['link_prefix']) {
            throw new InvalidArgumentException('cattophp.marketing.landing_prefix is small letters, digits and hyphens, up to 20, such as landing, and not the tracking links\' prefix.');
        }
        foreach ($marketing['returns_excluded'] as $prefix) {
            if (!str_starts_with($prefix, '/')) {
                throw new InvalidArgumentException(sprintf('Each of cattophp.marketing.returns_excluded is the start of a path, such as /admin: "%s" is not.', $prefix));
            }
        }
    }

    private function core(): void
    {
        $marketing = $this->marketing;
        $services = $this->services;
        // Read by the tracking links' and landing pages' routes as they are made (config/routes/marketing_links.php, marketing_landing.php).
        $this->container->parameters()->set('cattophp.marketing.link_prefix', $marketing['link_prefix']);
        $this->container->parameters()->set('cattophp.marketing.landing_prefix', $marketing['landing_prefix']);
        $connection = $this->connection();
        if ($marketing['project'] !== null && $marketing['project'] !== '') {
            $services->alias(MarketingProject::class, (string) $marketing['project']);
        } else {
            $services->set(MarketingProject::class, NoProject::class);
        }
        $services->set(MarketingSettingGroup::class)->tag(ServiceTags::SETTINGS);
        $services->set(MarketingSettings::class)->args([service(Settings::class)])->public();
        $services->set(MarketingPermissions::class)->tag(ServiceTags::PERMISSIONS);
        $services->set(MarketingActionWords::class)->tag(ServiceTags::ACTION_WORDS);
        $files = rtrim($marketing['files_folder'], '/') . '/marketing';
        $services->set(Pictures::class)->args([$connection, $files . '/pictures', [$files . '/adverts']])->public();
        $services->set(Campaigns::class)->args([$connection, service(MarketingProject::class), service(ActivityLog::class), service('clock'), service(Installation::class), service(Pictures::class)])->public();
        $services->set(Channels::class)->args([$connection, service(ActivityLog::class), service(IconLibrary::class)])->public();
        $services->set(Adverts::class)->args([$connection, service(Campaigns::class), service(Pictures::class), service(MarketingSettings::class)])->public();
        $services->set(Publications::class)->args([$connection, service(Campaigns::class), service(Channels::class), service(Adverts::class)])->public();
        $services->set(Spend::class)->args([$connection, service(Campaigns::class)])->public();
        $services->set(Visitors::class)->args([$connection])->public();
        $services->set(Touches::class)->args([$connection])->public();
        $services->set(TrackingLinks::class)->args([$connection, service(Campaigns::class), service(Publications::class), service(Channels::class), service(Visitors::class), service(Touches::class),
            service(MarketingSettings::class), service(NetworkLookup::class), service(Installation::class), $marketing['link_prefix']])->public();
        $services->set(CampaignFigures::class)->args([$connection, service(MarketingProject::class), service(MarketingSettings::class), service(Installation::class)])->public();
        $services->set(Events::class)->args([$connection])->public();
        $services->set(MarketingRecords::class)->args([service(Visitors::class), $connection, service(Events::class)])->tag(ServiceTags::FORGETS_ACCOUNTS);

        // Landing pages: the kinds of section and the picture sources (CattoPHP's and the project's, by their tags), the
        // templates, the pages and their sections, kept versions, the journey's events and the presses.
        if ($marketing['landing_project'] !== null && $marketing['landing_project'] !== '') {
            $services->alias(LandingProject::class, (string) $marketing['landing_project']);
        } else {
            $services->set(LandingProject::class, GenericLanding::class)->args([service('security.authorization_checker')->nullOnInvalid()]);
        }
        // CattoPHP's kinds first, in the order the Add a section cards show them; a project's after them.
        foreach ([HeroKind::class, WordsKind::class, PictureKind::class, VideoKind::class, OffersKind::class, AudienceKind::class, OutcomesKind::class, BenefitsKind::class,
            CountdownKind::class, FaqKind::class, CtaKind::class] as $order => $kind) {
            $services->set($kind)->args(match ($kind) {
                OffersKind::class => [service(MarketingProject::class)],
                VideoKind::class => [service(Adverts::class)],
                default => [],
            })->tag(ServiceTags::LANDING_SECTION, ['priority' => 200 - $order]);
        }
        $services->set(SectionKinds::class)->args([tagged_iterator(ServiceTags::LANDING_SECTION)])->public();
        $services->set(UploadedPictures::class)->args([service(Pictures::class), service(MarketingSettings::class), service(Adverts::class)])->tag(ServiceTags::LANDING_PICTURE_SOURCE, ['priority' => 100]);
        $services->set(OfferPicture::class)->args([service(LandingProject::class)])->tag(ServiceTags::LANDING_PICTURE_SOURCE, ['priority' => 100]);
        $services->set(PictureSources::class)->args([tagged_iterator(ServiceTags::LANDING_PICTURE_SOURCE)])->public();
        if ($marketing['landing_builtin_templates']) {
            $services->set(BuiltInTemplates::class)->tag(ServiceTags::LANDING_TEMPLATES, ['priority' => 100]);
        }
        $services->set(LandingTemplates::class)->args([$connection, service(SectionKinds::class), tagged_iterator(ServiceTags::LANDING_TEMPLATES), service(ActivityLog::class),
            service(Campaigns::class), service(Pictures::class)])->public();
        $services->set(LandingVersions::class)->args([$connection, service(Campaigns::class)])->public();
        $services->set(LandingPages::class)->args([$connection, service(Campaigns::class), service(SectionKinds::class), service(LandingVersions::class), service(LandingTemplates::class),
            service(MarketingProject::class), service(PictureSources::class), service(Pictures::class), service(Events::class), service(ActivityLog::class),
            service('event_dispatcher')->nullOnInvalid(), service(MarketingSettings::class), $marketing['landing_prefix'], $marketing['link_prefix']])->public();
        $services->set(LandingSections::class)->args([$connection, service(LandingPages::class), service(SectionKinds::class), service(LandingVersions::class), service(LandingTemplates::class),
            service(PictureSources::class), service(Pictures::class)])->public();
        $services->set(Journey::class)->args([service(Events::class), service(Visitors::class), service(MarketingSettings::class), service(NetworkLookup::class),
            service('security.token_storage')->nullOnInvalid(), service('event_dispatcher')->nullOnInvalid(), service('logger'), service('clock')])->public();
        $services->set(LandingPresses::class)->args([service(LandingPages::class), service(Journey::class)])->public();
        // The public pages (config/routes/marketing_landing.php): a campaign's page drawn at its tracking link, an offer's
        // page, their pictures and buttons, all drawn by the one LandingViewBuilder the editor's previews use too.
        $services->set(LandingViewBuilder::class)->args([service(LandingPages::class), service(SectionKinds::class), service(Campaigns::class), service(LandingProject::class),
            service(MarketingProject::class), service(PictureSources::class), service(Installation::class), service(ContentSecurityPolicy::class)->nullOnInvalid(), service('twig'),
            service(PageRenderer::class)->nullOnInvalid()])->public();
        $services->set(TrackingLinkController::class)->args([service(TrackingLinks::class), service('logger'), service(LandingPages::class), service(LandingViewBuilder::class),
            service(Journey::class), service(Campaigns::class)])->public()->tag('controller.service_arguments');
        $services->set(LandingPublicController::class)->args([service(LandingPages::class), service(LandingViewBuilder::class), service(Journey::class), service(LandingPresses::class),
            service(Campaigns::class), service(LandingProject::class), service(LandingVersions::class), service(SectionKinds::class), service(MarketingSettings::class),
            service(MarketingProject::class), service(PictureSources::class), service('security.token_storage')->nullOnInvalid()])->public()->tag('controller.service_arguments');
        // The activity log's words and links for its records.
        $services->set(MarketingDetailWords::class)->args([service(MarketingProject::class), service(Channels::class)])->tag(ServiceTags::DETAIL_WORDS);
        $services->set(MarketingSubjectLinks::class)->args([service('router'), service(Campaigns::class), service(LandingPages::class), service(Visitors::class)])->tag(ServiceTags::SUBJECT_LINKS);
        if ($this->scheduler) {
            $services->set(MarketingTidyUpTask::class)->args([service(Touches::class), service(Visitors::class), service(MarketingSettings::class)])
                ->tag(ServiceTags::SCHEDULED_TASK, ['priority' => $marketing['task_priority']]);
        }
    }
}
