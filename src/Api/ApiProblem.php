<?php

declare(strict_types=1);

namespace CattoPHP\Api;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The one error document the API returns: {"error": {"status", "code", "message"}}, never cached. Every failure (a
 * missing token, a refused permission, a malformed body) is drawn here, so the authenticator, the exception listener and
 * a project's own routes cannot each drift a field; clients parse it.
 */
final class ApiProblem
{
    public static function response(int $status, string $code, string $message): JsonResponse
    {
        $response = new JsonResponse(['error' => ['status' => $status, 'code' => $code, 'message' => $message]], $status, [], false);
        $response->setEncodingOptions(JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
