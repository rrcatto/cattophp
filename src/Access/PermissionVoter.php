<?php

declare(strict_types=1);

namespace CattoPHP\Access;

use CattoPHP\Activity\NamedAccount;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants a permission of the catalogue to a signed-in account whose roles hold it. An administrator (an account with
 * Symfony's ROLE_ADMIN, which CattoPHP's sign-in gives every ADMIN) has every permission. A key not in the catalogue is
 * not this voter's, so no other voter can grant a mistyped one by accident.
 *
 * @extends Voter<string, mixed>
 */
final class PermissionVoter extends Voter
{
    public function __construct(private readonly Roles $roles)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return preg_match(Permission::PATTERN, $attribute) === 1 && $this->roles->catalogue()->has($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if ($user === null) {
            return false;
        }
        if (in_array('ROLE_ADMIN', $token->getRoleNames(), true)) {
            return true;
        }

        return $this->roles->may($user instanceof NamedAccount ? $user->accountId() : $user->getUserIdentifier(), $attribute);
    }
}
