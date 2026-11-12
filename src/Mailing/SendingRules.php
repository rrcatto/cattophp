<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The sending discipline a project keeps: the most copies an hour, a gentle first day that doubles each day after
 * (0 for none), the bounce and complaint rates (percentages) above which a campaign pauses itself, how many copies must
 * have gone before it is judged, and the sending hours (from and until, local time; the same for both means around the
 * clock, and an end before the start runs through midnight), and whether a copy's openings and clicks are recorded.
 */
final class SendingRules
{
    public function __construct(
        public readonly int $hourlyLimit = 300,
        public readonly int $firstDayLimit = 50,
        public readonly float $pauseBouncePercent = 2.0,
        public readonly float $pauseComplaintPercent = 0.1,
        public readonly int $judgeAfter = 20,
        public readonly string $sendFrom = '07:00',
        public readonly string $sendUntil = '20:00',
        public readonly bool $trackOpens = true,
        public readonly bool $trackClicks = true,
    ) {
    }

    /** Whether a moment falls within the sending hours, in the given time zone (the site's when none is given). */
    public function withinSendingHours(DateTimeInterface $at, ?DateTimeZone $zone = null): bool
    {
        if ($this->sendFrom === $this->sendUntil) return true;
        $now = DateTimeImmutable::createFromInterface($at)->setTimezone($zone ?? new DateTimeZone(date_default_timezone_get()))->format('H:i');

        return $this->sendFrom < $this->sendUntil ? $now >= $this->sendFrom && $now < $this->sendUntil : $now >= $this->sendFrom || $now < $this->sendUntil;
    }
}
