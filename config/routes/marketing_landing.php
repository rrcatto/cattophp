<?php

declare(strict_types=1);

use CattoPHP\Marketing\Pages\LandingPublicController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The Campaign Marketing Manager's public landing pages: an offer's page at /landing/{slug} (the first part being
 * cattophp.marketing.landing_prefix), and under it and under a campaign's own address /c/{campaign} (whose page the
 * tracking link draws, config/routes/marketing_links.php) the page's pictures, /picture/{ref}, and its buttons,
 * /go/{press}. For a project with the marketing module on, whose access control lets everyone open them
 * (`{ path: '^/(c|landing)/', roles: PUBLIC_ACCESS }`). A project imports it beside the tracking links':
 * `cattophp_marketing_landing: { resource: '@CattoPHPBundle/config/routes/marketing_landing.php' }`.
 */
return static function (RoutingConfigurator $routes): void {
    $slug = '[a-z0-9]+(?:-[a-z0-9]+)*';
    $campaign = '[A-Za-z0-9][A-Za-z0-9-]{1,39}';
    $picture = '[A-Za-z0-9][A-Za-z0-9._:-]{0,199}';
    $press = '[a-z0-9]{4,12}-[1-9][0-9]{0,2}';
    $routes->add('cattophp_marketing_landing', '/%cattophp.marketing.landing_prefix%/{slug}')->controller([LandingPublicController::class, 'page'])->methods(['GET'])
        ->requirements(['slug' => $slug]);
    $routes->add('cattophp_marketing_landing_picture', '/%cattophp.marketing.landing_prefix%/{slug}/picture/{ref}')->controller([LandingPublicController::class, 'picture'])
        ->methods(['GET'])->requirements(['slug' => $slug, 'ref' => $picture]);
    $routes->add('cattophp_marketing_landing_press', '/%cattophp.marketing.landing_prefix%/{slug}/go/{press}')->controller([LandingPublicController::class, 'press'])
        ->methods(['GET'])->requirements(['slug' => $slug, 'press' => $press]);
    $routes->add('cattophp_marketing_link_picture', '/%cattophp.marketing.link_prefix%/{campaign}/picture/{ref}')->controller([LandingPublicController::class, 'picture'])
        ->methods(['GET'])->requirements(['campaign' => $campaign, 'ref' => $picture]);
    $routes->add('cattophp_marketing_link_press', '/%cattophp.marketing.link_prefix%/{campaign}/go/{press}')->controller([LandingPublicController::class, 'press'])
        ->methods(['GET'])->requirements(['campaign' => $campaign, 'press' => $press]);
};
