<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Pages;

use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\MarketingMenu;
use CattoPHP\Marketing\MarketingProject;
use CattoPHP\Marketing\MarketingWiring;
use CattoPHP\ServiceTags;
use CattoPHP\Ui\IconLibrary;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The Campaign Marketing Manager's ready-made pages (config/routes/marketing.php) and their place in the menu, wired by
 * MarketingWiring, with what the Visitors and Sales pages read (VisitorList, OfferInterest) and say (SaleWords). A
 * page's controller is public and tagged controller.service_arguments; its arguments are autowired.
 */
final class PagesWiring
{
    /** The controllers of the pages under /marketing, each public and autowired. */
    public const CONTROLLERS = [OverviewController::class, CampaignsController::class, PublicationsController::class, AdvertsController::class, SettingsController::class,
        VisitorsController::class, SalesController::class];

    private function __construct()
    {
    }

    public static function register(MarketingWiring $wiring): void
    {
        $services = $wiring->services;
        $services->set(MarketingMenu::class)->args([service('router')])->tag(ServiceTags::MENU);
        $services->set(MarketingPages::class)->args([service(Campaigns::class), service(MarketingProject::class), service(IconLibrary::class)]);
        // What the Visitors and Sales pages, a campaign's page and the overview read and say of visitors and sales.
        $services->set(SaleWords::class);
        $services->set(VisitorList::class)->args([$wiring->connection(), service(MarketingProject::class)]);
        $services->set(OfferInterest::class)->args([$wiring->connection()]);
        foreach (self::CONTROLLERS as $controller) {
            $services->set($controller)->public()->tag('controller.service_arguments');
        }
        // The landing pages' list and New page, and a page's editor: public, for a project that mounts the editor under
        // its own address (Marketing\Pages\LandingEditor::routes() and an EditorPlace).
        $services->set(LandingEditor::class)->public();
        foreach ([LandingPagesController::class, LandingEditorController::class] as $controller) {
            $services->set($controller)->public()->tag('controller.service_arguments');
        }
    }
}
