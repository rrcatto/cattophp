<?php

declare(strict_types=1);

namespace CattoPHP\Access;

/**
 * Says which permissions a module or the application has. Each is a service tagged `cattophp.permissions` (done
 * automatically for a class implementing this).
 */
interface PermissionProvider
{
    public const TAG = 'cattophp.permissions';

    /** @return iterable<Permission> */
    public function permissions(): iterable;
}
