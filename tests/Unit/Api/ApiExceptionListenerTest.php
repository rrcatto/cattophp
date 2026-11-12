<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Api;

use CattoPHP\Api\ApiAccessDenied;
use CattoPHP\Api\ApiExceptionListener;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Throwable;

/** Each failure under the API's path as the API's error document, ported from cattolms's test of its listener. */
final class ApiExceptionListenerTest extends TestCase
{
    /** @return array<string,array{0:Throwable,1:int,2:string,3:string}> */
    public static function failures(): array
    {
        return [
            'a refused permission' => [new AccessDeniedException(), 403, 'forbidden', 'The authenticated account does not have the required permission.'],
            'a refused resource' => [new ApiAccessDenied('The authenticated account cannot manage this thing.'), 403, 'forbidden', 'The authenticated account cannot manage this thing.'],
            'a missing resource' => [new NotFoundHttpException('The thing could not be found.'), 404, 'not_found', 'The thing could not be found.'],
            'a bad argument' => [new InvalidArgumentException('The request body is not valid JSON.'), 422, 'invalid_request', 'The request body is not valid JSON.'],
            'a broken rule' => [new RuntimeException('That person has this already.'), 409, 'request_failed', 'That person has this already.'],
        ];
    }

    #[DataProvider('failures')]
    public function testEachFailureIsReportedInTheApiErrorDocument(
        Throwable $exception,
        int $status,
        string $code,
        string $message
    ): void {
        $response = $this->handle($exception, '/api/v1/things');

        self::assertNotNull($response);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame(
            ['status' => $status, 'code' => $code, 'message' => $message],
            json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['error']
        );
    }

    /**
     * Anything unrecognised is a 500 that says nothing.
     *
     * The message is deliberately discarded: an unexpected exception's text is written for whoever
     * reads the log, not for whoever called the API, and it is where internal detail leaks.
     */
    public function testAnUnrecognisedFailureSaysNothingAboutItself(): void
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'cattophp-api-log-');
        $previous = (string) ini_get('error_log');
        ini_set('error_log', $log);

        try {
            $response = $this->handle(new \LogicException('SQLSTATE[42P01]: undefined_table: relation "secrets"'), '/api/v1/things');
        } finally {
            ini_set('error_log', $previous);
        }

        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());
        $error = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['error'];
        self::assertSame('An unexpected error occurred.', $error['message']);
        self::assertStringNotContainsString('secrets', (string) $response->getContent());

        // The detail is not discarded, only redirected: whoever operates the platform needs it.
        self::assertStringContainsString('undefined_table', (string) file_get_contents($log));
        unlink($log);
    }

    /** A page outside the API's path is left to whatever draws pages; this listener has no opinion on it. */
    public function testAFailureOutsideTheApiIsNotTouched(): void
    {
        self::assertNull($this->handle(new NotFoundHttpException('Nope.'), '/things'));
    }

    private function handle(Throwable $exception, string $path): ?\Symfony\Component\HttpFoundation\Response
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new ExceptionEvent($kernel, Request::create($path), HttpKernelInterface::MAIN_REQUEST, $exception);
        (new ApiExceptionListener())->onException($event);

        return $event->getResponse();
    }
}
