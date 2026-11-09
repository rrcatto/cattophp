<?php

declare(strict_types=1);

namespace CattoPHP\Http;

use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * A refused form's values have one chance: once the next page has been drawn and sent, whatever it did not use is
 * forgotten, so nothing typed comes back on a later visit. A redirect, a part of a page htmx asked for and anything
 * that is not a page leave them waiting.
 */
final class TypedFormListener
{
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        if (!$event->isMainRequest() || !$request->isMethod('GET') || $response->isRedirection() || $request->headers->has('HX-Request')) {
            return;
        }
        $type = (string) $response->headers->get('Content-Type', 'text/html');
        if (!str_starts_with($type, 'text/html')) {
            return;
        }
        TypedForm::forget($request);
    }
}
