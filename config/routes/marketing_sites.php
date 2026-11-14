<?php

declare(strict_types=1);

use CattoPHP\Marketing\Pages\SiteReportsController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Where a connected site reaches the Campaign Marketing Manager: POST /webhooks/marketing takes its reports (the
 * registrations and sales of the visitors this site's links sent it), GET /webhooks/marketing/check answers its check of
 * the connection. Each is signed by the other site and answered 401 with an empty body when it is not, so they need no
 * sign-in: the project's access control lets everyone reach them (`{ path: '^/webhooks/', roles: PUBLIC_ACCESS }`) and
 * keeps them out of any CSRF check. A project with connected sites imports it, beside the tracking links' file:
 * `cattophp_marketing_sites: { resource: '@CattoPHPBundle/config/routes/marketing_sites.php' }`.
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add('cattophp_marketing_sites_receive', '/webhooks/marketing')->controller([SiteReportsController::class, 'receive'])->methods(['POST']);
    $routes->add('cattophp_marketing_sites_check', '/webhooks/marketing/check')->controller([SiteReportsController::class, 'check'])->methods(['GET']);
};
