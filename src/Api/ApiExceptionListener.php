<?php

declare(strict_types=1);

namespace CattoPHP\Api;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Throwable;

/**
 * An exception under the API's path, as the API's error document: a refused scope or permission is 403 forbidden,
 * something not found 404 not_found, a bad argument 422 invalid_request, a rule that will not be broken 409
 * request_failed, and anything else is logged and said as 500 server_error without its message. Clients parse these
 * codes. It decides nothing itself.
 */
final class ApiExceptionListener implements EventSubscriberInterface
{
    public function __construct(private readonly string $path = '/api/')
    {
    }

    /** @return array<string,array{0:string,1:int}> */
    public static function getSubscribedEvents(): array
    {
        // Ahead of Symfony's own ErrorListener, which would otherwise draw the HTML error page.
        return [KernelEvents::EXCEPTION => ['onException', 32]];
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), $this->path)) {
            return;
        }
        $event->setResponse(self::responseFor($event->getThrowable()));
    }

    private static function responseFor(Throwable $exception): JsonResponse
    {
        return match (true) {
            $exception instanceof AccessDeniedException, $exception instanceof ApiAccessDenied
                => ApiProblem::response(403, 'forbidden', $exception instanceof ApiAccessDenied ? $exception->getMessage() : 'The authenticated account does not have the required permission.'),
            $exception instanceof NotFoundHttpException => ApiProblem::response(404, 'not_found', $exception->getMessage() !== '' ? $exception->getMessage() : 'The requested resource does not exist.'),
            $exception instanceof InvalidArgumentException => ApiProblem::response(422, 'invalid_request', $exception->getMessage()),
            $exception instanceof RuntimeException => ApiProblem::response(409, 'request_failed', $exception->getMessage()),
            default => self::serverError($exception),
        };
    }

    private static function serverError(Throwable $exception): JsonResponse
    {
        error_log($exception->__toString());

        return ApiProblem::response(500, 'server_error', 'An unexpected error occurred.');
    }
}
