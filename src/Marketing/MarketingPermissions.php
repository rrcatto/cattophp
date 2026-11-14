<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use CattoPHP\Access\Permission;
use CattoPHP\Access\PermissionProvider;

/**
 * The Campaign Marketing Manager's permissions, given by a role on the Roles page: seeing it, managing campaigns and
 * what belongs to them, seeing who the visitors and customers are, and changing its settings and channels.
 */
final class MarketingPermissions implements PermissionProvider
{
    public const VIEW = 'MARKETING.VIEW';
    public const MANAGE = 'MARKETING.CAMPAIGN.MANAGE';
    public const PEOPLE = 'MARKETING.PEOPLE.VIEW';
    public const SETTINGS = 'MARKETING.SETTINGS.MANAGE';

    public function permissions(): iterable
    {
        yield new Permission(self::VIEW, 'Campaign Marketing Manager', 'See the Campaign Marketing Manager: campaigns, adverts, publications, spend and their figures.');
        yield new Permission(self::MANAGE, 'Campaign Marketing Manager', 'Make, change, copy, activate, pause, archive and delete campaigns, and their adverts, publications and spend.');
        yield new Permission(self::PEOPLE, 'Campaign Marketing Manager', 'See identifiable visitors and customers: who came by a campaign and what they did.');
        yield new Permission(self::SETTINGS, 'Campaign Marketing Manager', 'Change the Campaign Marketing Manager\'s settings and channels.');
    }
}
