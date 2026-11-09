<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

use LogicException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * The firewall's authenticator. It reads no request itself: the sign-in pages decide, step by step, and finish with
 * Symfony's own `Security::login()` through it, so the session is renewed and the usual events are dispatched.
 */
final class SignInAuthenticator extends AbstractAuthenticator
{
    public function supports(Request $request): ?bool
    {
        return false;
    }

    public function authenticate(Request $request): Passport
    {
        throw new LogicException('The sign-in pages sign people in; this authenticator reads no request.');
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }
}
