<?php

declare(strict_types=1);

use CattoPHP\Admin\ActivityController;
use CattoPHP\Admin\RolesController;
use CattoPHP\Admin\SettingsController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Administration's Settings, Roles and Activity log, in the project's look, behind CattoPHP's own permissions
 * (PLATFORM.SETTINGS.VIEW and SYSTEM.SETTINGS.MANAGE, PLATFORM.ROLE.VIEW and SYSTEM.ROLE.MANAGE, PLATFORM.ACTIVITY.VIEW).
 * For a project with Symfony's session and sign-in. A project imports it, under /admin unless it gives another prefix:
 * `cattophp_admin: { resource: '@CattoPHPBundle/config/routes/admin.php' }`.
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add('cattophp_admin_settings', '/admin/settings')->controller([SettingsController::class, 'show'])->methods(['GET']);
    $routes->add('cattophp_admin_settings_save', '/admin/settings/{group}')->controller([SettingsController::class, 'save'])->methods(['POST'])->requirements(['group' => '[a-z][a-z0-9_]*']);
    $routes->add('cattophp_admin_settings_reset_group', '/admin/settings/{group}/reset')->controller([SettingsController::class, 'resetGroup'])->methods(['POST'])->requirements(['group' => '[a-z][a-z0-9_]*']);
    $routes->add('cattophp_admin_settings_reset', '/admin/settings/{group}/reset/{name}')->controller([SettingsController::class, 'reset'])->methods(['POST'])->requirements(['group' => '[a-z][a-z0-9_]*', 'name' => '[A-Z][A-Z0-9_]*']);
    $routes->add('cattophp_admin_roles', '/admin/roles')->controller([RolesController::class, 'list'])->methods(['GET']);
    $routes->add('cattophp_admin_role_make', '/admin/roles')->controller([RolesController::class, 'make'])->methods(['POST']);
    $routes->add('cattophp_admin_role_new', '/admin/roles/new')->controller([RolesController::class, 'new'])->methods(['GET']);
    $routes->add('cattophp_admin_role', '/admin/roles/{key}')->controller([RolesController::class, 'show'])->methods(['GET'])->requirements(['key' => RolesController::KEY]);
    $routes->add('cattophp_admin_role_save', '/admin/roles/{key}')->controller([RolesController::class, 'save'])->methods(['POST'])->requirements(['key' => RolesController::KEY]);
    $routes->add('cattophp_admin_role_delete', '/admin/roles/{key}/delete')->controller([RolesController::class, 'delete'])->methods(['POST'])->requirements(['key' => RolesController::KEY]);
    $routes->add('cattophp_admin_role_give', '/admin/roles/{key}/holders')->controller([RolesController::class, 'give'])->methods(['POST'])->requirements(['key' => RolesController::KEY]);
    $routes->add('cattophp_admin_role_take', '/admin/roles/{key}/holders/take-away')->controller([RolesController::class, 'takeAway'])->methods(['POST'])->requirements(['key' => RolesController::KEY]);
    $routes->add('cattophp_admin_activity', '/admin/activity')->controller([ActivityController::class, 'list'])->methods(['GET']);
};
