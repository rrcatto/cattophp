<?php

declare(strict_types=1);

namespace CattoPHP\Api;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Whether an API token carries a scope, such as #[IsGranted('courses:read')]. A scope says what the token was issued
 * for, never what the person may do: a route states the permission as well, which CattoPHP's permission voter decides.
 * Scopes are the project's (lower case, with a colon); permissions are upper case with dots, so the two never collide.
 *
 * @extends Voter<string, mixed>
 */
final class ApiScopeVoter extends Voter
{
    public function __construct(private readonly ApiTokens $tokens)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $this->tokens->isScope($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof ApiUser && $user->identity->hasScope($attribute);
    }
}
