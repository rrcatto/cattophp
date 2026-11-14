<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing\Kinds;

use CattoPHP\Marketing\Landing\CopyRefused;
use CattoPHP\Marketing\Landing\LandingContext;
use CattoPHP\Marketing\Landing\LandingCopy;
use CattoPHP\Marketing\Landing\LandingVisit;
use DateTimeImmutable;

/**
 * A countdown: words ("The special ends in") and the days, hours and minutes left, counting to the campaign's end on a
 * campaign's page, or on an offer's page to a date and time typed (in the installation's time zone). It is shown only
 * while there is time left, and never while the campaign is not running; always the full width; once a page.
 */
final class CountdownKind extends BaseKind
{
    public const DEFAULT_WORDS = 'The special ends in';

    public function key(): string
    {
        return 'countdown';
    }

    public function label(): string
    {
        return 'Countdown';
    }

    public function description(): string
    {
        return 'Days, hours and minutes to the end date.';
    }

    public function icon(): string
    {
        return 'timer';
    }

    public function once(): bool
    {
        return true;
    }

    public function alwaysFull(): bool
    {
        return true;
    }

    public function defaultHeading(): string
    {
        return '';
    }

    public function defaults(): array
    {
        return ['words' => '', 'ends_at' => ''];
    }

    public function normalise(array $input, LandingContext $page): array
    {
        $endsAt = is_string($input['ends_at'] ?? null) ? trim(str_replace('T', ' ', $input['ends_at'])) : '';
        if ($page->isCampaignPage()) {
            $endsAt = '';
        } elseif ($endsAt !== '' && self::instant($endsAt, $page->now) === null) {
            throw new CopyRefused('ends_at', 'Type the end as a date and time, such as 2026-11-30 23:59.');
        }

        return ['words' => LandingCopy::line($input['words'] ?? '', 120, 'The words', 'words'), 'ends_at' => $endsAt === '' ? '' : (string) self::instant($endsAt, $page->now)?->format('Y-m-d H:i')];
    }

    public function missing(array $data, LandingContext $page): array
    {
        if ($page->isCampaignPage()) {
            return $page->campaign?->endsAt === null ? ['Countdown: the campaign has no end date, so there is nothing to count down to.'] : [];
        }

        return ($data['ends_at'] ?? '') === '' ? ['Countdown has no end chosen.'] : [];
    }

    public function summary(array $data, LandingContext $page): string
    {
        $end = $this->end($data, $page);

        return $end === null ? 'No end date yet' : 'Counts down to ' . $end->format('j M Y, H:i');
    }

    public function form(LandingContext $page): array
    {
        return ['limits' => ['words' => 120], 'placeholder' => self::DEFAULT_WORDS, 'campaign_end' => $page->campaign?->endsAt?->format('j M Y, H:i'), 'typed_end' => $page->isOfferPage()];
    }

    /** The words, the end (as an ISO 8601 instant for the script) and the days, hours and minutes left, drawn by the server; null once the time is up or while the campaign is not running. */
    public function view(array $data, LandingContext $page, LandingVisit $visit): ?array
    {
        $end = $this->end($data, $page);
        if ($end === null || $end <= $page->now || ($page->isCampaignPage() && !$page->running)) {
            return null;
        }
        $left = $end->getTimestamp() - $page->now->getTimestamp();
        $words = is_string($data['words'] ?? null) && trim($data['words']) !== '' ? $data['words'] : self::DEFAULT_WORDS;

        return ['heading' => '', 'paragraphs' => [], 'words' => $words, 'ends_at' => $end->format(DATE_ATOM), 'days' => intdiv($left, 86400), 'hours' => intdiv($left % 86400, 3600),
            'minutes' => intdiv($left % 3600, 60)];
    }

    /** @param array<string,mixed> $data */
    private function end(array $data, LandingContext $page): ?DateTimeImmutable
    {
        if ($page->isCampaignPage()) {
            return $page->campaign?->endsAt;
        }

        return is_string($data['ends_at'] ?? null) && $data['ends_at'] !== '' ? self::instant($data['ends_at'], $page->now) : null;
    }

    /** A date and time typed, in the time zone of the moment given; a date alone is the last minute of that day. */
    private static function instant(string $typed, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $zone = $now->getTimezone();
        foreach (['!Y-m-d H:i', '!Y-m-d H:i:s'] as $format) {
            $at = DateTimeImmutable::createFromFormat($format, $typed, $zone);
            if ($at !== false && $at->format(substr($format, 1)) === $typed) {
                return $at;
            }
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $typed, $zone);

        return $date !== false && $date->format('Y-m-d') === $typed ? $date->setTime(23, 59) : null;
    }
}
