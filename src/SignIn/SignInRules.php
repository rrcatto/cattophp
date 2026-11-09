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
        return $this->mayUse($account->email, $this->isAdministrator($account));
    }

    /**
     * Whether someone with this address may sign in, or register where the project lets people register: always an
     * administrator, everyone when sign-in is not limited, else a listed address. For an address with no account yet.
     */
    public function mayUse(string $email, bool $administrator): bool
    {
        return $administrator || !$this->limited() || $this->isListed($email);
    }

    /** Whether only administrators and the listed addresses may sign in: AUTH_SIGNIN says anything but EVERYONE. */
    public function limited(): bool
    {
        return strtoupper(trim($this->settings->get('AUTH_SIGNIN'))) !== 'EVERYONE';
    }

    public function isListed(string $email): bool
    {
        return in_array(mb_strtolower(trim($email)), $this->allowedEmails(), true);
    }

    public function mustUseTwoStep(SignInAccount $account): bool
    {
        return match (strtoupper($this->settings->get('AUTH_TWO_STEP'))) {
            'EVERYONE' => true,
            'CHOICE' => false,
            default => $this->isAdministrator($account),
        };
    }

    /** @return list<string> the listed addresses, in small letters, each once */
    public function allowedEmails(): array
    {
        return self::addresses($this->settings->get('AUTH_SIGNIN_ALLOWED_EMAILS'));
    }

    /**
     * The addresses in a list as AUTH_SIGNIN_ALLOWED_EMAILS writes it (separated by spaces, new lines, commas or
     * semicolons), in small letters, each once; an entry that is not an email address is left out.
     *
     * @return list<string>
     */
    public static function addresses(string $text): array
    {
        $listed = preg_split('/[\s,;]+/', mb_strtolower($text)) ?: [];

        return array_values(array_unique(array_filter($listed, static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));
    }
}
