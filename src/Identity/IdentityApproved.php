<?php

declare(strict_types=1);

namespace CattoPHP\Identity;

/**
 * What a project does when an account's identity is approved (issue what waited for it, say), called once the approval
 * is recorded and only for an account old enough (`identity.minimum_age`). A failure here never undoes the approval.
 */
interface IdentityApproved
{
    public function approved(string $account): void;
}
