<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use CattoPHP\Activity\DetailWords;
use CattoPHP\Marketing\Landing\LandingSection;

/**
 * How the activity log and a campaign's history word the Campaign Marketing Manager's details: an amount as the project
 * writes money, a status and an advert's kind in words, a channel by its name, and names for the details whose keys
 * say less than they mean (code_before is "Code before"; copied_from "Copied from").
 */
final class MarketingDetailWords implements DetailWords
{
    private const LABELS = ['amount_minor' => 'Amount', 'status_before' => 'Status before', 'code_before' => 'Code before', 'copied_from' => 'Copied from',
        'label_before' => 'Name before', 'label' => 'Name', 'spent_on' => 'Spent on', 'channels' => 'Order',
        'page' => 'Landing page', 'slug_before' => 'Address before', 'slug' => 'Address', 'from_position' => 'Moved from', 'to_position' => 'Moved to', 'shown_when' => 'Shown',
        'changed' => 'What changed', 'skipped' => 'Left out', 'first' => 'For the first time',
        'site' => 'Connected site', 'address' => 'Address', 'address_before' => 'Address before', 'name_before' => 'Name before', 'result' => 'Result',
        'reports_dropped' => 'Waiting reports dropped', 'clock_apart_seconds' => 'Clocks apart by', 'visitor' => 'Visitor', 'visitors' => 'Visitors made anonymous',
        'message' => 'Mailing message', 'publication' => 'Publication'];

    public function __construct(private readonly MarketingProject $project = new NoProject(), private readonly ?Channels $channels = null)
    {
    }

    public function label(string $action, string $key): ?string
    {
        return str_starts_with($action, 'marketing.') ? (self::LABELS[$key] ?? null) : null;
    }

    public function value(string $action, string $key, mixed $value): ?string
    {
        if (!str_starts_with($action, 'marketing.')) {
            return null;
        }

        return match (true) {
            $key === 'amount_minor' && is_int($value) => $this->project->money($value),
            in_array($key, ['status', 'status_before'], true) && is_string($value) => Campaign::SHOWN[$value] ?? null,
            $key === 'kind' && is_string($value) => Advert::KINDS[$value] ?? null,
            $key === 'channel' && is_string($value) => $this->channels?->get($value)->label ?? null,
            $key === 'shown_when' && is_string($value) => LandingSection::SHOWN_WHEN[$value] ?? null,
            $key === 'clock_apart_seconds' && is_int($value) => self::seconds($value),
            $key === 'channels' && is_array($value) => implode(', ', array_map(fn (mixed $channel): string => is_string($channel) ? ($this->channels?->get($channel)->label ?? $channel) : '', $value)),
            default => null,
        };
    }

    /** How far apart two clocks were, in words: "40 seconds", "7 minutes". */
    private static function seconds(int $seconds): string
    {
        $seconds = abs($seconds);
        if ($seconds < 120) {
            return $seconds . ($seconds === 1 ? ' second' : ' seconds');
        }
        $minutes = intdiv($seconds, 60);

        return $minutes . ' minutes';
    }
}
