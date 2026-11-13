<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

use CattoPHP\Settings\Settings;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads the signed-in account on every request, so a sign-in ends at once when the account is disabled or removed,
 * may no longer sign in, or the sign-in has lasted AUTH_SESSION_TTL; and, where the project keeps sign-ins a person can
 * see and end (`sign_in.sessions`), when this browser's sign-in was ended or is no longer kept.
 *
 * @implements UserProviderInterface<SignInUser>
 */
final class SignInUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly SignInAccounts $accounts,
        private readonly SignInRules $rules,
        private readonly Settings $settings,
        private readonly RequestStack $requests,
        private readonly ClockInterface $clock,
        private readonly ?SignInSessions $sessions = null,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): SignInUser
    {
        $account = $this->accounts->forId($identifier);
        if ($account === null || !$this->rules->maySignIn($account)) {
            throw new UserNotFoundException('This account cannot sign in.');
        }

        return SignInUser::of($account, $this->rules->isAdministrator($account));
    }

    public function refreshUser(UserInterface $user): SignInUser
    {
        if (!$user instanceof SignInUser) {
            throw new UnsupportedUserException('Not an account of this sign-in.');
        }
        $request = $this->requests->getMainRequest();
        $session = $request !== null && $request->hasSession() ? $request->getSession() : null;
        $at = $session?->get(SignInFlow::SIGNED_IN_AT);
        $lasts = $this->settings->duration('AUTH_SESSION_TTL')->lengthInSeconds();
        if ($session === null || !is_int($at) || $at <= $this->clock->now()->getTimestamp() - $lasts) {
            throw new UserNotFoundException('The sign-in has run out.');
        }
        if ($this->sessions !== null && !$this->sessions->holds($session, $user->id)) {
            throw new UserNotFoundException('The sign-in was ended.');
        }

        return $this->loadUserByIdentifier($user->id);
    }

    public function supportsClass(string $class): bool
    {
        return $class === SignInUser::class;
    }
}
