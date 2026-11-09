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
 * may no longer sign in, or the sign-in has lasted AUTH_SESSION_TTL.
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
        $at = $request !== null && $request->hasSession() ? $request->getSession()->get(SignInFlow::SIGNED_IN_AT) : null;
        $lasts = $this->settings->duration('AUTH_SESSION_TTL')->lengthInSeconds();
        if (!is_int($at) || $at <= $this->clock->now()->getTimestamp() - $lasts) {
            throw new UserNotFoundException('The sign-in has run out.');
        }

        return $this->loadUserByIdentifier($user->id);
    }

    public function supportsClass(string $class): bool
    {
        return $class === SignInUser::class;
    }
}
