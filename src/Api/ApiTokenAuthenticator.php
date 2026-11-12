<?php

declare(strict_types=1);

namespace CattoPHP\Api;

use CattoPHP\Security\KeyedHash;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * An API request's sign-in, from its Bearer token (ApiTokens decides), and the firewall's entry point: a request with no
 * token is refused in the same JSON document as one with a bad token (Symfony's own entry point answers in HTML, the
 * wrong answer to a JSON client). Every request the firewall covers needs a token, so nothing relies on a route
 * remembering to ask for one.
 */
final class ApiTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly ApiTokens $tokens, private readonly ?KeyedHash $keyedHash = null)
    {
    }

    public function supports(Request $request): ?bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $address = (string) $request->getClientIp();
        try {
            $identity = $this->tokens->authenticateBearer(
                (string) $request->headers->get('Authorization', ''),
                $this->keyedHash?->of('api-address', $address !== '' ? $address : 'unknown'),
                trim((string) $request->headers->get('User-Agent', 'Unknown browser')),
            );
        } catch (ApiRefused $refused) {
            // The token's own problem, in the words clients have always been shown.
            throw new CustomUserMessageAuthenticationException($refused->getMessage(), [], 0, $refused);
        }

        return new SelfValidatingPassport(new UserBadge($identity->accountId, static fn (): ApiUser => new ApiUser($identity)));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    /** Only a message CattoPHP wrote is shown; Symfony's own wording says more about the framework than the request. */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return self::refuse($exception instanceof CustomUserMessageAuthenticationException ? $exception->getMessage() : 'The API token is invalid, expired or revoked.');
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::refuse($authException?->getMessage() ?? 'A Bearer API token is required.');
    }

    private static function refuse(string $message): Response
    {
        $response = ApiProblem::response(401, 'unauthenticated', $message);
        $response->headers->set('WWW-Authenticate', 'Bearer');

        return $response;
    }
}
