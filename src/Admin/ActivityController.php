<?php

declare(strict_types=1);

namespace CattoPHP\Admin;

use CattoPHP\Account\AccountRecords;
use CattoPHP\Activity\ActionWords;
use CattoPHP\Activity\ActivityDetails;
use CattoPHP\Activity\ActivityEntry;
use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\SubjectLinks;
use CattoPHP\Http\LiveRefresh;
use CattoPHP\Http\StockPages;
use CattoPHP\Installation\Installation;
use CattoPHP\Ui\Pager;
use CattoPHP\Ui\Pagination;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Administration > Activity log (`config/routes/admin.php`): what the activity log holds, newest first, a page at a time,
 * filtered by any action it holds: who did it, what, what it was about (linked to the record where the project's
 * SubjectLinks give its address), its details in words (ActivityDetails, with the project's DetailWords; never a secret)
 * and the request it came from. It refreshes itself every 15 seconds while it is open. Reading it needs PLATFORM.ACTIVITY.VIEW.
 */
final class ActivityController
{
    /** The list's name: its pager's region and its page's values (activity_page, activity_page_size). */
    public const LIST = 'activity';

    /** @var array<string,string> each action's words, CattoPHP's and the project's */
    private readonly array $words;

    /** @var list<SubjectLinks> */
    private readonly array $links;

    /**
     * @param iterable<ActionWords> $words
     * @param iterable<SubjectLinks> $links where the project's records live, for the About column's links
     */
    public function __construct(
        private readonly StockPages $pages,
        private readonly ActivityLog $activity,
        private readonly AccountNames $names,
        private readonly Installation $installation,
        iterable $words = [],
        private readonly ?ActivityDetails $details = null,
        iterable $links = [],
    ) {
        $all = [];
        foreach ($words as $provider) {
            $all = $provider->words() + $all;
        }
        $this->words = $all;
        $this->links = is_array($links) ? array_values($links) : iterator_to_array($links, false);
    }

    public function list(Request $request): Response
    {
        $this->pages->deny('PLATFORM.ACTIVITY.VIEW');
        LiveRefresh::notAVisit($request);
        $present = $this->activity->actions();
        $action = (string) $request->query->get('action', '');
        if (!in_array($action, $present, true)) {
            $action = '';
        }
        $filter = $action === '' ? null : $action;
        $total = $this->activity->count($filter);
        $pagination = Pagination::create($request->query->get(self::LIST . '_page'), $request->query->get(self::LIST . '_page_size'), $total);
        $rows = array_map($this->row(...), $this->activity->recent($pagination->pageSize, $filter, offset: $pagination->offset));
        $actions = [['value' => '', 'label' => 'Everything', 'selected' => $action === '']];
        $choices = [];
        foreach ($present as $key) {
            $choices[] = ['value' => $key, 'label' => $this->words($key), 'selected' => $key === $action];
        }
        usort($choices, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));
        $base = $this->pages->path('cattophp_admin_activity');

        return $this->pages->page($request, 'admin-activity', '@CattoPHP/admin/activity.html.twig', [
            'title' => 'Activity log',
            'lead' => 'Every change, who made it and when, newest first. Times are in ' . $this->installation->timeZone()->getName() . '.',
            'entries' => $rows,
            'actions' => [...$actions, ...$choices],
            'action_words' => $action === '' ? '' : $this->words($action),
            'filtered' => $action !== '',
            'total' => $total,
            'pagination' => Pager::payload($pagination, self::LIST, $base, 'Activity log', ['action' => $action]),
            'base' => $base,
        ]);
    }

    /** An action in words: the words given for it, else as it is named. */
    public function words(string $action): string
    {
        return $this->words[$action] ?? $action;
    }

    /** @return array<string,mixed> */
    private function row(ActivityEntry $entry): array
    {
        return [
            'at' => $this->installation->localTime($entry->at)->format('j M Y, H:i:s'),
            // Nobody's id: the system, or an account forgotten since (AccountRecords), by the name the entry keeps.
            'who' => $entry->actorId === null ? ($entry->actorName !== null && $entry->actorName !== '' ? $entry->actorName : 'The system') : $this->names->name($entry->actorId, $entry->actorName),
            'what' => $this->words($entry->action),
            'action' => $entry->action,
            'about' => $this->about($entry->subjectType, $entry->subjectId),
            'about_href' => $this->aboutHref($entry->subjectType, $entry->subjectId),
            'details' => ($this->details ?? new ActivityDetails($this->installation))->lines($entry->action, $entry->details),
            'from' => $entry->ipAddress ?? '',
            'reference' => $entry->requestReference ?? '',
        ];
    }

    /**
     * The address of what an entry is about, from the first of the project's SubjectLinks that gives one: a path on the
     * site or an http or https address, else none (an empty string).
     */
    private function aboutHref(?string $type, ?string $id): string
    {
        if ($type === null || $id === null || $type === '' || $id === '') {
            return '';
        }
        foreach ($this->links as $links) {
            $href = $links->href($type, $id);
            if ($href !== null && $href !== '') {
                return preg_match('#^(/(?![/\\\\])|https?://[^/])#i', $href) === 1 ? $href : '';
            }
        }

        return '';
    }

    private function about(?string $type, ?string $id): string
    {
        if ($type === 'account' && $id === null) {
            return AccountRecords::FORGOTTEN;
        }
        if ($type === null || $id === null) {
            return '';
        }
        if ($type === 'account') {
            $account = $this->names->account($id);

            return $account === null ? $this->names->name($id) : $account->name . ' (' . $account->email . ')';
        }

        return match ($type) {
            'setting' => 'The setting ' . $id,
            'role' => 'The role ' . $id,
            // A project's own subject by its type in words and its reference: "Loan sd4jr2uh".
            default => ActivityDetails::sentence($type) . ' ' . $id,
        };
    }
}
