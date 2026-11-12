<?php

declare(strict_types=1);

namespace CattoPHP\Protection;

use Symfony\Component\HttpKernel\Event\RequestEvent;

/** The lockout gate as the first thing Symfony does with a request, before the session and the firewall. */
final class LockoutListener
{
    /** Before Symfony's session (128) and security firewall (8). */
    public const PRIORITY = 4096;

    public function __construct(private readonly LockoutGate $gate)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $request = $event->getRequest();
        if ($this->gate->refuses((string) $request->getClientIp(), $request->getPathInfo(), $request->getMethod(), (string) $request->headers->get('User-Agent', ''))) {
            $event->setResponse(LockoutGate::response());
        }
    }
}
