<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use CattoPHP\Theme\MenuEntry;
use CattoPHP\Theme\MenuProvider;
use CattoPHP\Theme\MenuSection;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The Campaign Marketing Manager's place in a project's menu: a drop-down "Marketing" (section key marketing, icon
 * nav-marketing) with its Overview, Campaigns, Landing pages, Visitors, Sales and Settings, each shown to someone with the
 * permission its page needs (Visitors MARKETING.PEOPLE.VIEW). Visitors and Sales name the symbols nav-visitors and
 * nav-sales; a project whose sprite lacks them draws the section's.
 * Wired with the module; it gives nothing in a project that has not imported the pages' routes
 * (config/routes/marketing.php), so a menu never links a page that is not there. A project's PageProject adds the
 * section to its navbar from Theme\Menus.
 */
final class MarketingMenu implements MenuProvider
{
    public const SECTION = 'marketing';

    public function __construct(private readonly ?UrlGeneratorInterface $urls = null)
    {
    }

    public function menuSections(): iterable
    {
        try {
            $this->urls?->generate('cattophp_marketing_overview');
        } catch (RouteNotFoundException) {
            return;
        }
        yield new MenuSection(self::SECTION, 'Marketing', 'nav-marketing', [
            MenuEntry::route('Overview', 'cattophp_marketing_overview', permission: MarketingPermissions::VIEW),
            MenuEntry::route('Campaigns', 'cattophp_marketing_campaigns', permission: MarketingPermissions::VIEW),
            MenuEntry::route('Landing pages', 'cattophp_marketing_landing_pages', permission: MarketingPermissions::VIEW),
            MenuEntry::route('Visitors', 'cattophp_marketing_visitors', permission: MarketingPermissions::PEOPLE, icon: 'nav-visitors'),
            MenuEntry::route('Sales', 'cattophp_marketing_sales', permission: MarketingPermissions::VIEW, icon: 'nav-sales'),
            MenuEntry::route('Settings', 'cattophp_marketing_settings', permission: MarketingPermissions::SETTINGS),
        ]);
    }
}
