<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Pages;

use CattoPHP\Marketing\Campaign;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Channel;
use CattoPHP\Marketing\MarketingProject;
use CattoPHP\Marketing\Period;
use CattoPHP\Ui\IconLibrary;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What the Campaign Marketing Manager's pages say the same way: a campaign found by its code or not found, a date as
 * "13 Nov 2026" in the installation's time zone, when a campaign runs, its status as a badge, money as the project
 * writes it, a number in words, the period the figures are for (from the address) with its choices as feature cards, and
 * an icon from the library for a feature card or a channel.
 */
final class MarketingPages
{
    /** The tone of each status a person sees, for its badge. */
    public const TONES = ['draft' => 'neutral', 'scheduled' => 'info', 'active' => 'success', 'paused' => 'warning', 'ended' => 'neutral', 'archived' => 'permanent'];

    /** Each period the figures can be for, in words, and the icon of its card. */
    public const PERIODS = [
        'today' => ['Today', 'sun'],
        '7d' => ['7 days', 'calendar-days'],
        '30d' => ['30 days', 'calendar-range'],
        'month' => ['This month', 'calendar'],
        'last_month' => ['Last month', 'calendar-fold'],
        'year' => ['This year', 'calendar-check'],
        'all' => ['All', 'infinity'],
        'dates' => ['Two dates', 'calendar-clock'],
    ];

    public function __construct(
        private readonly Campaigns $campaigns,
        private readonly MarketingProject $project,
        private readonly IconLibrary $icons,
    ) {
    }

    /** A campaign by its code, or the site's ordinary not found. */
    public function campaign(string $code): Campaign
    {
        return $this->campaigns->get($code) ?? throw new NotFoundHttpException('There is no such campaign.');
    }

    public function now(): DateTimeImmutable
    {
        return $this->campaigns->now();
    }

    /** A day as a page shows it: 13 Nov 2026, in the installation's time zone; '' for none. */
    public function day(?DateTimeImmutable $at): string
    {
        return $at === null ? '' : $at->setTimezone($this->campaigns->zone())->format('j M Y');
    }

    /** A moment as a page shows it: 13 Nov 2026, 14:05, in the installation's time zone. */
    public function moment(DateTimeImmutable $at): string
    {
        return $at->setTimezone($this->campaigns->zone())->format('j M Y, H:i');
    }

    /** When a campaign runs, in words: "1 Nov 2026 to 30 Nov 2026", "From 1 Nov 2026", "No dates set". */
    public function runs(Campaign $campaign): string
    {
        $from = $this->day($campaign->startsAt);
        $to = $this->day($campaign->endsAt);

        return match (true) {
            $from !== '' && $to !== '' => $from . ' to ' . $to,
            $from !== '' => 'From ' . $from,
            $to !== '' => 'Until ' . $to,
            default => 'No dates set',
        };
    }

    /** How long an active campaign has left, in words ("17 days left", "Ends today"), or '' when that says nothing. */
    public function left(Campaign $campaign): string
    {
        $now = $this->now();
        if ($campaign->shownStatus($now) !== 'active' || $campaign->endsAt === null) {
            return '';
        }
        $days = (int) $now->setTime(0, 0)->diff($campaign->endsAt->setTimezone($this->campaigns->zone())->setTime(0, 0))->format('%a');

        return match ($days) {
            0 => 'Ends today',
            1 => '1 day left',
            default => $days . ' days left',
        };
    }

    /** @return array{label:string,tone:string,key:string} a campaign's status as a badge */
    public function status(Campaign $campaign): array
    {
        $key = $campaign->shownStatus($this->now());

        return ['label' => Campaign::SHOWN[$key], 'tone' => self::TONES[$key], 'key' => $key];
    }

    public function money(int $minor): string
    {
        return $this->project->money($minor);
    }

    /** An amount of money as a spreadsheet reads it: 4150.00. */
    public static function decimal(int $minor): string
    {
        return ($minor < 0 ? '-' : '') . intdiv(abs($minor), 100) . '.' . str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Words a project gave, as they read inside a sentence: "Card" is "card", but an abbreviation keeps its capitals
     * ("EFT" stays "EFT", never "eFT"), as does a name the project wrote with a second capital.
     */
    public static function inSentence(string $words): string
    {
        $first = (string) (preg_split('/\s+/', trim($words))[0] ?? '');

        return preg_match('/^\p{Lu}.*\p{Lu}/u', $first) === 1 ? $words : lcfirst($words);
    }

    /** A number in words, with its noun: "1 click", "1,204 clicks". */
    public static function count(int $number, string $one, string $many): string
    {
        return number_format($number) . ' ' . ($number === 1 ? $one : $many);
    }

    /** An icon of the library as SVG for a feature card or a channel, or the megaphone when it has none of that name. */
    public function icon(string $name): string
    {
        return $this->icons->svg($name) ?? (string) $this->icons->svg('megaphone');
    }

    /** @return array{value:string,label:string,icon:string} a channel as a feature card */
    public function channelCard(Channel $channel): array
    {
        return ['value' => $channel->key, 'label' => $channel->label, 'icon' => $this->icon($channel->icon)];
    }

    /**
     * The period the figures are for, from the address: a named one (period=7d), or two dates (from and to, both
     * included; either may be left empty), else the last 30 days. Dates that cannot be read are said to be so, and the
     * last 30 days shown.
     *
     * @return array{period:Period,name:string,from:string,to:string,words:string,problem:string}
     */
    public function period(Request $request, string $default = '30d'): array
    {
        $name = self::text($request->query->get('period', ''));
        $from = trim(self::text($request->query->get('from', '')));
        $to = trim(self::text($request->query->get('to', '')));
        $now = $this->now();
        if ($name === 'dates' || ($name === '' && ($from !== '' || $to !== ''))) {
            $period = $from === '' && $to === '' ? Period::all() : Period::dates($from, $to, $this->campaigns->zone());
            if ($period !== null) {
                return ['period' => $period, 'name' => 'dates', 'from' => $from, 'to' => $to, 'words' => $this->datesWords($period), 'problem' => ''];
            }

            return ['period' => Period::named($default, $now), 'name' => $default, 'from' => $from, 'to' => $to, 'words' => self::periodWords($default),
                'problem' => 'Those dates could not be read, or the second comes before the first: type them as 2026-11-01, so the last 30 days are shown instead.'];
        }
        if (!isset(Period::NAMES[$name])) {
            $name = $default;
        }

        return ['period' => Period::named($name, $now), 'name' => $name, 'from' => '', 'to' => '', 'words' => self::periodWords($name), 'problem' => ''];
    }

    /** @return list<array{value:string,label:string,icon:string}> the periods as feature cards */
    public function periodCards(): array
    {
        $cards = [];
        foreach (self::PERIODS as $value => [$label, $icon]) {
            $cards[] = ['value' => $value, 'label' => $label, 'icon' => $this->icon($icon)];
        }

        return $cards;
    }

    /**
     * What the address carries for a period, for a link that keeps it.
     *
     * @param array{name:string,from:string,to:string} $period as period() gives it
     * @return array<string,string>
     */
    public static function periodQuery(array $period): array
    {
        return $period['name'] === 'dates' ? ['period' => 'dates', 'from' => $period['from'], 'to' => $period['to']] : ['period' => $period['name']];
    }

    private static function periodWords(string $name): string
    {
        return match ($name) {
            'today' => 'today',
            '7d' => 'the last 7 days',
            '30d' => 'the last 30 days',
            'month' => 'this month',
            'last_month' => 'last month',
            'year' => 'this year',
            default => 'all time',
        };
    }

    private function datesWords(Period $period): string
    {
        $from = $this->day($period->from);
        $to = $this->day($period->to?->modify('-1 day'));

        return match (true) {
            $from !== '' && $to !== '' => $from . ' to ' . $to,
            $from !== '' => 'from ' . $from,
            $to !== '' => 'up to ' . $to,
            default => 'all time',
        };
    }

    public static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
