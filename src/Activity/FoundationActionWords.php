<?php

declare(strict_types=1);

namespace CattoPHP\Activity;

/** The words for the actions CattoPHP's foundations and modules record. */
final class FoundationActionWords implements ActionWords
{
    public const WORDS = [
        'sign_in.link_sent' => 'Sign-in link sent',
        'sign_in.joining_link_sent' => 'Sign-in link sent to an address joining',
        'sign_in.link_refused' => 'Sign-in link refused: this account may not sign in now',
        'sign_in.signed_in' => 'Signed in',
        'sign_in.signed_out' => 'Signed out',
        'sign_in.wrong_code' => 'Wrong one-time code typed',
        'sign_in.session_ended' => 'A sign-in ended',
        'sign_in.sessions_ended' => 'Every other sign-in ended',
        'two_step.set_up' => 'Two-step sign-in set up',
        'two_step.reset' => 'Two-step sign-in turned off',
        'two_step.recovery_codes_renewed' => 'New recovery codes made',
        'two_step.recovery_code_used' => 'Recovery code used',
        'two_step.wrong_code' => 'Wrong code typed on Sign-in and security',
        'account.forgotten' => 'An account was deleted and its records forgotten',
        'settings.changed' => 'Setting changed',
        'settings.reset' => 'Setting reset',
        'access.role_made' => 'Role made',
        'access.role_changed' => 'Role changed',
        'access.role_renamed' => 'Role given a new key',
        'access.role_deleted' => 'Role deleted',
        'access.role_given' => 'Role given',
        'access.role_taken' => 'Role taken away',
        'theme.activated' => 'Theme chosen',
        'theme.imported' => 'Theme installed',
        'theme.uninstalled' => 'Theme removed',
        'theme.registry_resynchronised' => 'Installed themes listed again',
        'setup.step_marked' => 'Setup step marked',
        'setup.step_skipped' => 'Setup step skipped',
        'api.token_created' => 'API token made',
        'api.token_revoked' => 'API token revoked',
        'site.locked_out' => 'Locked out',
        'site.lockout_lifted' => 'Lockout lifted',
        'site.flag_not_a_problem' => 'Flag marked not a problem',
        'telegram.connected' => 'Telegram connected',
        'telegram.connection_dropped' => 'Telegram connection dropped',
        'telegram.connect_refused' => 'Telegram connection refused',
        'search.pages_updated' => 'Search words for pages saved',
    ];

    public function words(): array
    {
        return self::WORDS;
    }
}
