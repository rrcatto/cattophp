<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

use CattoPHP\Access\Roles;
use CattoPHP\Settings\Settings;

/**
 * Who may sign in and who must use two-step sign-in, from the settings. AUTH_SIGNIN: ADMIN (administrators and the
 * listed addresses, AUTH_SIGNIN_ALLOWED_EMAILS) or EVERYONE; anything else is ADMIN, the safe one. AUTH_TWO_STEP:
 * CHOICE (each person's), ADMINS (required of administrators) or EVERYONE; anything else is ADMINS.
 */
final class SignInRules
{
    public function __construct(private readonly Settings $settings, private readonly ?Roles $roles = null)
    {
    }

    public function isAdministrator(SignInAccount $account): bool
    {
        return $account->administrator || ($this->roles?->isAdministrator($account->id) ?? false);
    }

    public function maySignIn(SignInAccount $account): bool
    {
        if (strtoupper($this->settings->get('AUTH_SIGNIN')) === 'EVERYONE' || $this->isAdministrator($account)) {
            return true;
        }

        return in_array(mb_strtolower(trim($account->email)), $this->allowedEmails(), true);
    }

    public function mustUseTwoStep(SignInAccount $account): bool
    {
        return match (strtoupper($this->settings->get('AUTH_TWO_STEP'))) {
            'EVERYONE' => true,
            'CHOICE' => false,
            default => $this->isAdministrator($account),
        };
    }

    /** @return list<string> */
    public function allowedEmails(): array
    {
        $listed = preg_split('/[\s,;]+/', mb_strtolower($this->settings->get('AUTH_SIGNIN_ALLOWED_EMAILS'))) ?: [];

        return array_values(array_filter($listed, static fn (string $email): bool => $email !== ''));
    }
}
