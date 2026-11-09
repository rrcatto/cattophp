<?php

declare(strict_types=1);

namespace CattoPHP\Access;

/** The permissions of CattoPHP's own foundations. */
final class FoundationPermissions implements PermissionProvider
{
    public function permissions(): iterable
    {
        yield new Permission('PLATFORM.SETTINGS.VIEW', 'Settings', 'See the settings and where each value comes from (never a secret\'s value).');
        yield new Permission('SYSTEM.SETTINGS.MANAGE', 'Settings', 'Change and reset the settings.');
        yield new Permission('PLATFORM.ROLE.VIEW', 'Roles', 'See the roles, the permissions each grants and who holds them.');
        yield new Permission('SYSTEM.ROLE.MANAGE', 'Roles', 'Make, change and delete roles, and give them to people or take them away.');
        yield new Permission('PLATFORM.ACTIVITY.VIEW', 'Activity', 'Read the activity log.');
    }
}
