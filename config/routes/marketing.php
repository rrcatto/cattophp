<?php

declare(strict_types=1);

use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Channels;
use CattoPHP\Marketing\Pages\AdvertsController;
use CattoPHP\Marketing\Pages\CampaignsController;
use CattoPHP\Marketing\Pages\LandingEditor;
use CattoPHP\Marketing\Pages\LandingEditorController;
use CattoPHP\Marketing\Pages\LandingPagesController;
use CattoPHP\Marketing\Pages\OverviewController;
use CattoPHP\Marketing\Pages\PublicationsController;
use CattoPHP\Marketing\Pages\SalesController;
use CattoPHP\Marketing\Pages\SettingsController;
use CattoPHP\Marketing\Pages\VisitorsController;
use CattoPHP\Marketing\Sites\Site;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The Campaign Marketing Manager's pages, in the project's look, under /marketing: the overview, the campaigns, one
 * campaign with its publications, adverts and spend, the visitors and one visitor's journey, the sales and one sale,
 * the landing pages with each page's editor (its previews, versions and every action, LandingEditor::routes()), and its
 * settings and channels, behind its own permissions (MARKETING.VIEW to see, MARKETING.CAMPAIGN.MANAGE to change,
 * MARKETING.PEOPLE.VIEW for who visitors and customers are, MARKETING.SETTINGS.MANAGE for the settings and the connected
 * sites). For a project with the marketing module on, Symfony's session and sign-in. A project imports it, beside the
 * tracking links' file: `cattophp_marketing: { resource: '@CattoPHPBundle/config/routes/marketing.php' }`. A campaign is
 * addressed by its code, a publication by its code, an advert, a cost, a landing page and its sections and a visitor by
 * their references, a sale by its order's reference, a kept version as v and its number, never a row number.
 */
return static function (RoutingConfigurator $routes): void {
    $code = trim(Campaigns::CODE, '/^$');
    $publication = '[a-z0-9]{1,12}';
    $reference = '[a-z0-9]{8}';
    $channel = trim(Channels::KEY, '/^$');
    $campaign = ['code' => $code];
    $one = ['code' => $code, 'publication' => $publication];
    $advert = ['code' => $code, 'reference' => $reference];

    $routes->add('cattophp_marketing_overview', '/marketing')->controller([OverviewController::class, 'show'])->methods(['GET']);
    $routes->add('cattophp_marketing_overview_csv', '/marketing/figures.csv')->controller([OverviewController::class, 'download'])->methods(['GET']);

    $routes->add('cattophp_marketing_campaigns', '/marketing/campaigns')->controller([CampaignsController::class, 'list'])->methods(['GET']);
    $routes->add('cattophp_marketing_campaigns_csv', '/marketing/campaigns.csv')->controller([CampaignsController::class, 'download'])->methods(['GET']);
    $routes->add('cattophp_marketing_campaign_make', '/marketing/campaigns')->controller([CampaignsController::class, 'make'])->methods(['POST']);
    $routes->add('cattophp_marketing_campaign_new', '/marketing/campaigns/new')->controller([CampaignsController::class, 'new'])->methods(['GET']);
    $routes->add('cattophp_marketing_campaign', '/marketing/campaigns/{code}')->controller([CampaignsController::class, 'show'])->methods(['GET'])->requirements($campaign);
    $routes->add('cattophp_marketing_campaign_save', '/marketing/campaigns/{code}')->controller([CampaignsController::class, 'save'])->methods(['POST'])->requirements($campaign);
    $routes->add('cattophp_marketing_campaign_edit', '/marketing/campaigns/{code}/edit')->controller([CampaignsController::class, 'edit'])->methods(['GET'])->requirements($campaign);
    foreach (['activate', 'pause', 'archive', 'restore', 'copy', 'delete'] as $action) {
        $routes->add('cattophp_marketing_campaign_' . $action, '/marketing/campaigns/{code}/' . $action)->controller([CampaignsController::class, $action])->methods(['POST'])->requirements($campaign);
    }
    $routes->add('cattophp_marketing_spend_add', '/marketing/campaigns/{code}/spend')->controller([CampaignsController::class, 'addCost'])->methods(['POST'])->requirements($campaign);
    $routes->add('cattophp_marketing_spend_remove', '/marketing/campaigns/{code}/spend/{reference}/remove')->controller([CampaignsController::class, 'removeCost'])->methods(['POST'])->requirements($advert);

    $routes->add('cattophp_marketing_publication_new', '/marketing/campaigns/{code}/publications/new')->controller([PublicationsController::class, 'new'])->methods(['GET'])->requirements($campaign);
    $routes->add('cattophp_marketing_publication_make', '/marketing/campaigns/{code}/publications')->controller([PublicationsController::class, 'make'])->methods(['POST'])->requirements($campaign);
    $routes->add('cattophp_marketing_publication', '/marketing/campaigns/{code}/publications/{publication}')->controller([PublicationsController::class, 'show'])->methods(['GET'])->requirements($one);
    $routes->add('cattophp_marketing_publication_save', '/marketing/campaigns/{code}/publications/{publication}')->controller([PublicationsController::class, 'save'])->methods(['POST'])->requirements($one);
    $routes->add('cattophp_marketing_publication_qr', '/marketing/campaigns/{code}/publications/{publication}/qr.svg')->controller([PublicationsController::class, 'qr'])->methods(['GET'])->requirements($one);
    foreach (['retire', 'restore', 'delete'] as $action) {
        $routes->add('cattophp_marketing_publication_' . $action, '/marketing/campaigns/{code}/publications/{publication}/' . $action)->controller([PublicationsController::class, $action])->methods(['POST'])->requirements($one);
    }

    $routes->add('cattophp_marketing_advert_new', '/marketing/campaigns/{code}/adverts/new')->controller([AdvertsController::class, 'new'])->methods(['GET'])->requirements($campaign);
    $routes->add('cattophp_marketing_advert_make', '/marketing/campaigns/{code}/adverts')->controller([AdvertsController::class, 'make'])->methods(['POST'])->requirements($campaign);
    $routes->add('cattophp_marketing_advert', '/marketing/campaigns/{code}/adverts/{reference}')->controller([AdvertsController::class, 'show'])->methods(['GET'])->requirements($advert);
    $routes->add('cattophp_marketing_advert_save', '/marketing/campaigns/{code}/adverts/{reference}')->controller([AdvertsController::class, 'save'])->methods(['POST'])->requirements($advert);
    $routes->add('cattophp_marketing_advert_picture', '/marketing/campaigns/{code}/adverts/{reference}/picture')->controller([AdvertsController::class, 'picture'])->methods(['GET'])->requirements($advert);
    foreach (['retire', 'restore', 'delete'] as $action) {
        $routes->add('cattophp_marketing_advert_' . $action, '/marketing/campaigns/{code}/adverts/{reference}/' . $action)->controller([AdvertsController::class, $action])->methods(['POST'])->requirements($advert);
    }

    $routes->add('cattophp_marketing_landing_pages', '/marketing/landing-pages')->controller([LandingPagesController::class, 'list'])->methods(['GET']);
    $routes->add('cattophp_marketing_landing_make', '/marketing/landing-pages')->controller([LandingPagesController::class, 'make'])->methods(['POST']);
    $routes->add('cattophp_marketing_landing_new', '/marketing/landing-pages/new')->controller([LandingPagesController::class, 'new'])->methods(['GET']);
    $routes->add('cattophp_marketing_landing_template_delete', '/marketing/landing-pages/templates/{template}/delete')->controller([LandingPagesController::class, 'deleteTemplate'])
        ->methods(['POST'])->requirements(['template' => $reference]);
    LandingEditor::routes($routes, LandingEditorController::ROUTE, '/marketing/landing-pages/{reference}', [LandingEditorController::class, 'answer'], ['reference' => $reference]);

    // The visitors, by their references (never the cookie's token), and the sales, by the order's reference (a connected
    // site's under that site's key too).
    $visitor = ['reference' => '[a-z0-9]{8,10}'];
    $routes->add('cattophp_marketing_visitors', '/marketing/visitors')->controller([VisitorsController::class, 'list'])->methods(['GET']);
    $routes->add('cattophp_marketing_visitors_csv', '/marketing/visitors.csv')->controller([VisitorsController::class, 'download'])->methods(['GET']);
    $routes->add('cattophp_marketing_visitor', '/marketing/visitors/{reference}')->controller([VisitorsController::class, 'show'])->methods(['GET'])->requirements($visitor);
    $routes->add('cattophp_marketing_visitor_csv', '/marketing/visitors/{reference}/journey.csv')->controller([VisitorsController::class, 'downloadJourney'])->methods(['GET'])->requirements($visitor);
    $routes->add('cattophp_marketing_visitor_forget', '/marketing/visitors/{reference}/forget')->controller([VisitorsController::class, 'forget'])->methods(['POST'])->requirements($visitor);
    $sale = ['reference' => '[^/\s]{1,60}'];
    $routes->add('cattophp_marketing_sales', '/marketing/sales')->controller([SalesController::class, 'list'])->methods(['GET']);
    $routes->add('cattophp_marketing_sales_csv', '/marketing/sales.csv')->controller([SalesController::class, 'download'])->methods(['GET']);
    $routes->add('cattophp_marketing_sales_lines_csv', '/marketing/sales-lines.csv')->controller([SalesController::class, 'downloadLines'])->methods(['GET']);
    $routes->add('cattophp_marketing_sale', '/marketing/sales/{reference}')->controller([SalesController::class, 'show'])->methods(['GET'])->requirements($sale);
    $routes->add('cattophp_marketing_sale_from_site', '/marketing/sales/{site}/{reference}')->controller([SalesController::class, 'showFromSite'])->methods(['GET'])
        ->requirements($sale + ['site' => trim(Site::KEY, '/^$')]);

    $routes->add('cattophp_marketing_settings', '/marketing/settings')->controller([SettingsController::class, 'show'])->methods(['GET']);
    $routes->add('cattophp_marketing_settings_save', '/marketing/settings')->controller([SettingsController::class, 'save'])->methods(['POST']);
    $routes->add('cattophp_marketing_settings_reset_group', '/marketing/settings/reset')->controller([SettingsController::class, 'resetGroup'])->methods(['POST']);
    $routes->add('cattophp_marketing_settings_reset', '/marketing/settings/reset/{name}')->controller([SettingsController::class, 'reset'])->methods(['POST'])->requirements(['name' => 'MARKETING_[A-Z0-9_]+']);
    $routes->add('cattophp_marketing_channel_add', '/marketing/settings/channels')->controller([SettingsController::class, 'addChannel'])->methods(['POST']);
    $routes->add('cattophp_marketing_channel_save', '/marketing/settings/channels/{key}')->controller([SettingsController::class, 'saveChannel'])->methods(['POST'])->requirements(['key' => $channel]);
    foreach (['move' => 'moveChannel', 'retire' => 'retireChannel', 'restore' => 'restoreChannel', 'delete' => 'deleteChannel'] as $action => $method) {
        $routes->add('cattophp_marketing_channel_' . $action, '/marketing/settings/channels/{key}/' . $action)->controller([SettingsController::class, $method])->methods(['POST'])->requirements(['key' => $channel]);
    }

    // Connected sites, a card on the settings page (#connected-sites): added, renamed or given another address, checked,
    // given a new secret, their undelivered reports sent again, and removed (Drop connection).
    $site = ['key' => trim(Site::KEY, '/^$')];
    $routes->add('cattophp_marketing_site_add', '/marketing/settings/sites')->controller([SettingsController::class, 'addSite'])->methods(['POST']);
    $routes->add('cattophp_marketing_site_save', '/marketing/settings/sites/{key}')->controller([SettingsController::class, 'saveSite'])->methods(['POST'])->requirements($site);
    foreach (['check' => 'checkSite', 'secret' => 'changeSiteSecret', 'send' => 'sendSiteAgain', 'remove' => 'removeSite'] as $action => $method) {
        $routes->add('cattophp_marketing_site_' . $action, '/marketing/settings/sites/{key}/' . $action)->controller([SettingsController::class, $method])->methods(['POST'])->requirements($site);
    }
};
