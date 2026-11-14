<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use CattoPHP\Marketing\Pictures;
use CattoPHP\Marketing\Adverts;
use CattoPHP\Marketing\CampaignFigures;
use CattoPHP\Marketing\CampaignInput;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Channels;
use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\Journey;
use CattoPHP\Marketing\Journey\Readers;
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
use CattoPHP\Marketing\Landing\OfferPicture;
use CattoPHP\Marketing\Landing\PictureSources;
use CattoPHP\Marketing\Landing\SectionKinds;
use CattoPHP\Marketing\Landing\UploadedPictures;
use CattoPHP\Marketing\MarketingProject;
use CattoPHP\Marketing\MarketingSettingGroup;
use CattoPHP\Marketing\MarketingSettings;
use CattoPHP\Marketing\PublicationInput;
use CattoPHP\Marketing\Publications;
use CattoPHP\Marketing\Sales\SaleAttribution;
use CattoPHP\Marketing\Sales\Sales;
use CattoPHP\Marketing\Spend;
use CattoPHP\Marketing\Touches;
use CattoPHP\Marketing\TrackingLinks;
use CattoPHP\Marketing\Visitors;
use CattoPHP\Network\NetworkFacts;
use CattoPHP\Network\NetworkLookup;
use CattoPHP\Tests\Kernel\App\ExampleMarketingProject;
use CattoPHP\Ui\IconLibrary;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The Campaign Marketing Manager's services put together by hand on a test database, as the bundle wires them, with the
 * foundations' test stores and clock, the example project's offers (or another project's), and a network lookup that
 * knows two documentation ranges: 198.51.100.0/24 in South Africa and 203.0.113.0/24 in the United Kingdom. The landing
 * pages come with CattoPHP's kinds, picture sources and templates, GenericLanding as the project's part, a journey with a
 * recording logger, and a dispatcher that keeps every event it is given.
 */
final class MarketingHarness
{
    public readonly Foundations $site;
    public readonly MarketingSettings $settings;
    public readonly MarketingProject $project;
    public readonly Pictures $pictures;
    public readonly Campaigns $campaigns;
    public readonly Channels $channels;
    public readonly Adverts $adverts;
    public readonly Publications $publications;
    public readonly Spend $spend;
    public readonly Visitors $visitors;
    public readonly Touches $touches;
    public readonly TrackingLinks $links;
    public readonly CampaignFigures $figures;
    public readonly string $folder;
    public readonly SectionKinds $kinds;
    public readonly PictureSources $pictureSources;
    public readonly LandingProject $landingProject;
    public readonly LandingTemplates $templates;
    public readonly LandingVersions $versions;
    public readonly Events $events;
    public readonly LandingPages $pages;
    public readonly LandingSections $sections;
    public readonly Readers $readers;
    public readonly Journey $journey;
    public readonly LandingPresses $presses;
    public readonly RecordingLogger $logger;
    public readonly RecordingDispatcher $dispatcher;
    public readonly SaleAttribution $saleAttribution;
    public readonly Sales $sales;

    /** @param array<string,string> $env what .env gives */
    public function __construct(Connection $connection, array $env = [], ?MarketingProject $project = null, ?TokenStorageInterface $tokens = null)
    {
        $this->folder = Foundations::folder();
        $this->site = new Foundations($env, settingsProviders: [new MarketingSettingGroup()]);
        $this->settings = new MarketingSettings($this->site->settings);
        $this->project = $project ?? new ExampleMarketingProject();
        $this->pictures = new Pictures($connection, $this->folder . '/marketing/pictures');
        $this->campaigns = new Campaigns($connection, $this->project, $this->site->activity, $this->site->clock, $this->site->installation, $this->pictures);
        $this->channels = new Channels($connection, $this->site->activity, new IconLibrary());
        $this->adverts = new Adverts($connection, $this->campaigns, $this->pictures, $this->settings);
        $this->publications = new Publications($connection, $this->campaigns, $this->channels, $this->adverts);
        $this->spend = new Spend($connection, $this->campaigns);
        $this->visitors = new Visitors($connection);
        $this->touches = new Touches($connection);
        $this->links = new TrackingLinks($connection, $this->campaigns, $this->publications, $this->channels, $this->visitors, $this->touches, $this->settings, self::network(),
            $this->site->installation);
        $this->figures = new CampaignFigures($connection, $this->project, $this->settings, $this->site->installation);
        $this->kinds = new SectionKinds([new HeroKind(), new WordsKind(), new PictureKind(), new VideoKind($this->adverts), new OffersKind($this->project), new AudienceKind(),
            new OutcomesKind(), new BenefitsKind(), new CountdownKind(), new FaqKind(), new CtaKind()]);
        $this->landingProject = new GenericLanding();
        $this->pictureSources = new PictureSources([new UploadedPictures($this->pictures, $this->settings, $this->adverts), new OfferPicture($this->landingProject)]);
        $this->templates = new LandingTemplates($connection, $this->kinds, [new BuiltInTemplates()], $this->site->activity, $this->campaigns, $this->pictures);
        $this->versions = new LandingVersions($connection, $this->campaigns);
        $this->events = new Events($connection);
        $this->dispatcher = new RecordingDispatcher();
        $this->logger = new RecordingLogger();
        $this->pages = new LandingPages($connection, $this->campaigns, $this->kinds, $this->versions, $this->templates, $this->project, $this->pictureSources, $this->pictures, $this->events,
            $this->site->activity, $this->dispatcher, $this->settings);
        $this->sections = new LandingSections($connection, $this->pages, $this->kinds, $this->versions, $this->templates, $this->pictureSources, $this->pictures);
        $this->readers = new Readers($this->visitors, $this->touches, $this->settings, self::network(), $tokens, $this->site->clock);
        $this->journey = new Journey($this->events, $this->visitors, $this->settings, self::network(), $tokens, $this->dispatcher, $this->logger, $this->site->clock, $this->readers);
        $this->presses = new LandingPresses($this->pages, $this->journey);
        $this->saleAttribution = new SaleAttribution($connection);
        $this->sales = new Sales($connection, $this->events, $this->visitors, $this->touches, $this->saleAttribution, $this->settings, self::network(), null, $this->dispatcher,
            $this->logger, $this->site->clock);
    }

    public function remove(): void
    {
        Foundations::remove($this->folder);
    }

    /** A campaign made, and activated unless a draft is asked for. */
    public function campaign(string $name, string $code = '', bool $active = true, string $budget = '', string $destination = '/'): \CattoPHP\Marketing\Campaign
    {
        $campaign = $this->campaigns->create(new CampaignInput($name, $code, budget: $budget, destination: $destination));

        return $active ? $this->campaigns->activate($campaign->code) : $campaign;
    }

    /** A publication of a campaign on a channel, its code suggested. */
    public function publication(string $campaignCode, string $channel, string $place = '', string $cost = '', string $publishedOn = ''): \CattoPHP\Marketing\Publication
    {
        return $this->publications->create($campaignCode, new PublicationInput($channel, place: $place, cost: $cost, publishedOn: $publishedOn));
    }

    public static function network(): NetworkLookup
    {
        return new class implements NetworkLookup {
            public function facts(string $address): NetworkFacts
            {
                return match (true) {
                    str_starts_with($address, '198.51.100.') => new NetworkFacts('ZA', kind: 'home', address: $address),
                    str_starts_with($address, '203.0.113.') => new NetworkFacts('GB', kind: 'home', address: $address),
                    default => NetworkFacts::unknown($address),
                };
            }
        };
    }
}
