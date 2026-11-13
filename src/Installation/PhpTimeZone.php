<?php

declare(strict_types=1);

namespace CattoPHP\Installation;

use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * PHP's own time zone kept to the installation's (APP_TIMEZONE, a setting, so the Settings page's choice over .env's): set
 * as every request starts, before anything else runs (kernel.request, PRIORITY), as every console command starts
 * (console.command), and before each run of the scheduler, once a minute under --watch, so a time zone changed on the
 * Settings page applies without a restart. Everything that reads PHP's time zone then reads the installation's: a
 * scheduled task's time of day, a document's dates, the mailing's sending hours. A time zone PHP does not know, or a
 * setting that cannot be read, leaves PHP's time zone as it was (a project may set it itself as it starts; both read the
 * same setting).
 */
final class PhpTimeZone
{
    /** Before every other listener on a request, CattoPHP's lockout gate among them. */
    public const PRIORITY = 8192;

    public function __construct(private readonly Installation $installation)
    {
    }

    /** Sets PHP's time zone to the installation's, and says which; null when it names none (PHP's is left as it was). */
    public function apply(): ?string
    {
        $zone = $this->installation->chosenTimeZone();
        if ($zone === null) {
            return null;
        }
        if (date_default_timezone_get() !== $zone->getName()) {
            date_default_timezone_set($zone->getName());
        }

        return $zone->getName();
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->apply();
        }
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $this->apply();
    }
}
