<?php

declare(strict_types=1);

namespace CattoPHP\Protection;

use CattoPHP\Activity\NamedAccount;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Throwable;

/** The signed-in person by CattoPHP's sign-in (or any Symfony sign-in), an administrator when they hold the permission. */
final class SignedInVisitors implements VisitorAccounts
{
    public function __construct(
        private readonly ?TokenStorageInterface $tokens = null,
        private readonly ?AuthorizationCheckerInterface $authorization = null,
        private readonly string $administratorPermission = 'PLATFORM.SETTINGS.VIEW',
    ) {
    }

    public function current(): ?array
    {
        $user = $this->tokens?->getToken()?->getUser();
        if ($user === null) return null;
        try {
            $administrator = $this->authorization?->isGranted($this->administratorPermission) === true;
        } catch (Throwable) {
            $administrator = false;
        }

        return ['id' => $user instanceof NamedAccount ? $user->accountId() : $user->getUserIdentifier(), 'administrator' => $administrator];
    }
}
