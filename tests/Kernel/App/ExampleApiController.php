<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel\App;

use CattoPHP\Api\ApiUser;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** A project's API routes: one guarded by a scope and a permission, and two that fail in the ways the error document covers. */
final class ExampleApiController
{
    #[IsGranted('example:read')]
    #[IsGranted('PLATFORM.SETTINGS.VIEW')]
    public function me(#[CurrentUser] ApiUser $user): JsonResponse
    {
        return new JsonResponse(['account' => $user->accountId(), 'name' => $user->accountName(), 'scopes' => $user->identity->scopes]);
    }

    public function missing(): JsonResponse
    {
        throw new NotFoundHttpException('There is no such thing.');
    }

    public function invalid(): JsonResponse
    {
        throw new InvalidArgumentException('Enter a title.');
    }
}
