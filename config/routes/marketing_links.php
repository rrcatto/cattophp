<?php

declare(strict_types=1);

use CattoPHP\Marketing\Pages\TrackingLinkController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The Campaign Marketing Manager's public tracking links: /c/{campaign} (which also reads ?src= and the utm_* tags) and
 * /c/{campaign}/{publication}, the first part being cattophp.marketing.link_prefix. For a project with the marketing
 * module on, whose access control lets everyone open them (`{ path: '^/c/', roles: PUBLIC_ACCESS }`). A project imports
 * it: `cattophp_marketing_links: { resource: '@CattoPHPBundle/config/routes/marketing_links.php' }`.
 */
return static function (RoutingConfigurator $routes): void {
    $campaign = '[A-Za-z0-9][A-Za-z0-9-]{1,39}';
    $routes->add('cattophp_marketing_link', '/%cattophp.marketing.link_prefix%/{campaign}')->controller([TrackingLinkController::class, 'open'])->methods(['GET'])
        ->requirements(['campaign' => $campaign]);
    $routes->add('cattophp_marketing_link_publication', '/%cattophp.marketing.link_prefix%/{campaign}/{publication}')->controller([TrackingLinkController::class, 'open'])->methods(['GET'])
        ->requirements(['campaign' => $campaign, 'publication' => '[A-Za-z0-9]{1,12}']);
};
