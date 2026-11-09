<?php

declare(strict_types=1);

namespace CattoPHP\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The error page, in plain words: what kind of error it was (not found, not allowed, something broken, or a request
 * the site could not take) chooses the title and what the person can do next; the status code and the request's
 * reference are in small print, for reporting it. A server error never shows its reason unless the site is in
 * development, because the reason is as likely to be about the database as anything a person can act on: it goes to
 * the log with the reference. Someone else's record is simply not found. A request for JSON, and paths the project
 * names (its API), are left to the project.
 *
 * The page is `@CattoPHP/error.html.twig`, which a project replaces with its own at
 * `templates/bundles/CattoPHPBundle/error.html.twig`.
 */
final class ErrorPageListener
{
    public const TITLES = ['not_found' => 'Not found', 'forbidden' => 'Not allowed', 'broken' => 'Something went wrong', 'other' => 'Not done'];

    /** @param list<string> $skipPaths path prefixes whose errors are the project's own, such as /api/ */
    public function __construct(
        private readonly ErrorPageDrawer $drawer,
        private readonly LoggerInterface $logger,
        private readonly bool $debug,
        private readonly array $skipPaths = [],
    ) {
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        foreach ($this->skipPaths as $prefix) {
            if ($prefix !== '' && str_starts_with($request->getPathInfo(), $prefix)) {
                return;
            }
        }
        if ($request->getPreferredFormat() === 'json') {
            return;
        }
        $error = $event->getThrowable();
        $status = $error instanceof HttpExceptionInterface ? max(400, $error->getStatusCode()) : 500;
        $reference = RequestReference::of($request);
        if ($status >= 500) {
            $this->logger->error('Request {reference} failed: {class}: {message} at {file}:{line}', [
                'reference' => $reference,
                'class' => $error::class,
                'message' => $error->getMessage(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'exception' => $error,
            ]);
        }
        $kind = match (true) {
            $status === 404 => 'not_found',
            $status === 403 || $status === 401 => 'forbidden',
            $status >= 500 => 'broken',
            default => 'other',
        };
        $message = $status >= 500 && !$this->debug
            ? 'The page could not be shown. It is not something you did.'
            : self::plain($error->getMessage(), $status);
        $headers = $error instanceof HttpExceptionInterface ? $error->getHeaders() : [];
        try {
            $page = $this->drawer->draw(new ErrorPage($kind, self::TITLES[$kind], $status, $message, $reference, $request->isMethod('GET') ? $request->getRequestUri() : ''), $request);
        } catch (Throwable $drawing) {
            // The page itself could not be drawn: plain HTML instead, never the same drawer again.
            $this->logger->error('Request {reference}: the error page could not be drawn: {message}', ['reference' => $reference, 'message' => $drawing->getMessage(), 'exception' => $drawing]);
            $page = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<title>' . self::TITLES[$kind] . '</title></head><body><main><h1>' . self::TITLES[$kind] . '</h1><p>'
                . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><p><small>' . $status . ' · Reference ' . $reference
                . '</small></p><p><a href="/">Home</a></p></main></body></html>';
        }
        $event->setResponse(new Response($page, $status, $headers + ['Content-Type' => 'text/html; charset=utf-8']));
    }

    /** A message in words a person understands; the router's and the form token's own are put plainly. */
    public static function plain(string $message, int $status): string
    {
        return match (true) {
            str_starts_with($message, 'No route found') && $status === 405 => 'That address does not take this kind of request. Go back and use the page\'s own buttons and links.',
            str_starts_with($message, 'No route found'), $status === 404 && !self::isPlain($message) => 'The address may be mistyped, or the page has moved or been removed.',
            $message === 'Invalid CSRF token.', $message === 'Invalid request token.' => 'The form had expired, or was sent from a page opened long ago. Go back, reload the page and send it again.',
            $status === 403 && ($message === 'Access Denied.' || !self::isPlain($message)) => 'You do not have permission to open this.',
            trim($message) === '' => 'The request could not be completed.',
            default => $message,
        };
    }

    /** Whether a message was written for a person: a full sentence, not a class name or a code's own words. */
    private static function isPlain(string $message): bool
    {
        return preg_match('/^[A-Z][^\\\\{}<>]*[.!?]$/u', trim($message)) === 1 && !str_contains($message, '"') && !str_contains($message, 'object not found');
    }
}
