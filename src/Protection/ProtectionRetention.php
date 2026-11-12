<?php

declare(strict_types=1);

namespace CattoPHP\Protection;

use CattoPHP\Foundation\Duration;
use DateTimeImmutable;

/**
 * How long site protection keeps its records: the Site Log for `SITE_LOG_KEPT_FOR`, flags not raised again for
 * `SITE_FLAGS_KEPT_FOR` (never while the address is kept out), and ended lockouts as long as flags (never while the
 * firewall still holds them). A project's daily clean-up runs it.
 */
class ProtectionRetention
{
    public function __construct(
        private readonly ProtectionSettings $settings,
        private readonly SiteLog $log,
        private readonly Flags $flags,
        private readonly Lockouts $lockouts,
    ) {
    }

    /** @return array{site_requests:int,site_flags:int,site_lockouts:int} how many of each went */
    public function run(DateTimeImmutable $now): array
    {
        $values = $this->settings->values();
        $flags = Duration::parse((string) $values['flags_kept_for'])->before($now);

        return [
            'site_requests' => $this->log->deleteBefore(Duration::parse((string) $values['log_kept_for'])->before($now)),
            'site_flags' => $this->flags->deleteBefore($flags),
            'site_lockouts' => $this->lockouts->deleteEndedBefore($flags),
        ];
    }
}
