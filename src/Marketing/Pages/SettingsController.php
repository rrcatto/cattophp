<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Pages;

use CattoPHP\Admin\SettingsPage;
use CattoPHP\Http\StockPages;
use CattoPHP\Http\TypedForm;
use CattoPHP\Marketing\Channel;
use CattoPHP\Marketing\Channels;
use CattoPHP\Marketing\MarketingPermissions;
use CattoPHP\Marketing\MarketingRefused;
use CattoPHP\Marketing\MarketingSettingGroup;
use CattoPHP\Marketing\MarketingSubjectLinks;
use CattoPHP\Marketing\Sites\Site;
use CattoPHP\Marketing\Sites\SiteReports;
use CattoPHP\Marketing\Sites\Sites;
use CattoPHP\Settings\Settings;
use CattoPHP\Settings\SettingsRefused;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Campaign Marketing Manager's settings (`config/routes/marketing.php`, /marketing/settings): its group of settings,
 * saved here as Administration > Settings saves a group (each setting saying where its value comes from, with Reset;
 * Admin\SettingsPage and its template), and the channels a publication can go on: added, renamed, given another prefix
 * or icon, put in order with Up and Down, retired, restored, and deleted while no publication has used one. The tracking
 * links' first part is the project's configuration (cattophp.marketing.link_prefix), not a setting. And the Connected
 * sites card (#connected-sites): the other sites one campaign runs across (Marketing\Sites), each added with its name,
 * address and a shared secret (pasted, or made here by Make a secret, shown once to copy), the secret shown as asterisks
 * once saved, Check the connection, a new secret, Send them again for reports given up, and Drop connection; a site
 * whose check was accepted reads Connected in words. All need MARKETING.SETTINGS.MANAGE.
 */
final class SettingsController
{
    /** The icons a channel may have: generic ones from the library, never a company's logo. */
    public const ICONS = ['megaphone', 'users', 'camera', 'message-circle', 'briefcase', 'mail', 'hash', 'circle-play', 'music', 'send', 'search', 'printer',
        'radio', 'newspaper', 'globe', 'tv', 'mic', 'podcast', 'rss', 'smartphone', 'monitor', 'presentation', 'store', 'signpost', 'map-pin', 'image', 'video', 'link'];

    /** An icon's name in words where its name is not the word (tv is TV). */
    private const ICON_WORDS = ['tv' => 'TV', 'rss' => 'RSS feed', 'circle-play' => 'Play', 'message-circle' => 'Speech bubble', 'hash' => 'Hash sign'];

    /** The icon of each choice of which campaign a sale counts for. */
    /** The two answers to a browser that asks not to be tracked, each with what it means and its card's icon. */
    private const DO_NOT_TRACK = [
        'true' => ['Honour it', 'Its click counts, with its country; no visitor, cookie or IP address is kept', 'shield-check'],
        'false' => ['Count it like any other', 'Remembered as a visitor, with a cookie, as everyone else is', 'user-check'],
    ];

    private const SALE_ICONS = ['last' => 'flag', 'first' => 'signpost'];

    private const GROUP = MarketingSettingGroup::GROUP;

    public function __construct(
        private readonly StockPages $pages,
        private readonly MarketingPages $marketing,
        private readonly Settings $settings,
        private readonly SettingsPage $page,
        private readonly Channels $channels,
        private readonly Sites $sites,
        private readonly SiteReports $reports,
    ) {
    }

    public function show(Request $request): Response
    {
        $this->pages->deny(MarketingPermissions::SETTINGS);
        $errors = $this->pages->errors($request);
        $mayChange = $this->settings->canSave();
        $saveAction = $this->pages->path('cattophp_marketing_settings_save');
        $sent = TypedForm::take($request, $saveAction);
        $group = $this->page->group(self::GROUP, $sent, $sent === [] ? [] : $errors);
        foreach ($group['settings'] as $index => $setting) {
            if (is_array($setting) && ($setting['name'] ?? '') === 'MARKETING_SALE_COUNTS_FOR') {
                $cards = [];
                $selected = 'last';
                foreach (is_array($setting['choices'] ?? null) ? $setting['choices'] : [] as $choice) {
                    $value = MarketingPages::text($choice['value'] ?? '');
                    $cards[] = ['value' => $value, 'label' => MarketingPages::text($choice['label'] ?? ''), 'icon' => $this->marketing->icon(self::SALE_ICONS[$value] ?? 'flag')];
                    if (($choice['selected'] ?? false) === true) {
                        $selected = $value;
                    }
                }
                $group['settings'][$index] = $setting + ['cards' => $cards, 'cards_selected' => $selected];
            }
            // Honouring Do Not Track is a choice of two, as feature cards (a short choice is never a tick box).
            if (is_array($setting) && ($setting['name'] ?? '') === 'MARKETING_HONOUR_DO_NOT_TRACK') {
                $cards = [];
                foreach (self::DO_NOT_TRACK as $value => [$label, $note, $icon]) {
                    $cards[] = ['value' => $value, 'label' => $label, 'note' => $note, 'icon' => $this->marketing->icon($icon)];
                }
                $group['settings'][$index] = $setting + ['cards' => $cards, 'cards_selected' => ($setting['checked'] ?? false) === true ? 'true' : 'false'];
            }
        }
        $addAction = $this->pages->path('cattophp_marketing_channel_add');
        $added = TypedForm::take($request, $addAction);
        $editing = $this->channels->get(MarketingPages::text($request->query->get('channel', '')));
        $editAction = $editing === null ? '' : $this->pages->path('cattophp_marketing_channel_save', ['key' => $editing->key]);
        $edited = $editAction === '' ? [] : TypedForm::take($request, $editAction);
        $all = $this->channels->all();

        return $this->pages->page($request, 'marketing-settings', '@CattoPHP/marketing/settings.html.twig', [
            'title' => 'Marketing settings',
            'lead' => 'How visitors are remembered, which campaign a sale counts for, how long things are kept, the channels a publication can go on, and the sites connected to this one.',
            'kicker' => 'Campaign Marketing Manager',
            'may_change' => $mayChange,
            'why_not' => $mayChange ? '' : 'This installation keeps its settings in .env: change them there.',
            'group' => $group,
            'channels' => array_map(fn (Channel $channel, int $index): array => $this->channelRow($channel, $index, count($all)), $all, array_keys($all)),
            'add' => [
                'action' => $addAction,
                'label' => MarketingPages::text($added['label'] ?? ''),
                'link_prefix' => MarketingPages::text($added['link_prefix'] ?? ''),
                'icon' => MarketingPages::text($added['icon'] ?? 'megaphone'),
                'errors' => ($added === [] ? [] : $errors) + ['label' => '', 'link_prefix' => '', 'icon' => '', 'key' => ''],
                'open' => $added !== [],
            ],
            'edit' => $editing === null ? null : [
                'key' => $editing->key,
                'action' => $editAction,
                'label' => MarketingPages::text($edited['label'] ?? $editing->label),
                'link_prefix' => MarketingPages::text($edited['link_prefix'] ?? $editing->linkPrefix),
                'icon' => MarketingPages::text($edited['icon'] ?? $editing->icon),
                'errors' => ($edited === [] ? [] : $errors) + ['label' => '', 'link_prefix' => '', 'icon' => ''],
                'icon_cards' => $this->iconCards($editing->icon),
                'heading' => 'Change ' . $editing->label,
            ],
            'icon_cards' => $this->iconCards(''),
            'connected' => $this->connectedSites($request, $errors),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::SETTINGS);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_marketing_settings', [], 'settings-' . self::GROUP);
        }
        try {
            $done = $this->page->save(self::GROUP, $request->request->all(), $this->pages->actor());
        } catch (SettingsRefused $refused) {
            return $this->pages->refused($request, 'The settings were not saved: ' . $refused->getMessage(), 'cattophp_marketing_settings', [], 'settings-' . self::GROUP, errors: $refused->errors());
        }

        return $this->pages->done($request, $done, 'cattophp_marketing_settings', [], 'settings-' . self::GROUP);
    }

    public function resetGroup(Request $request): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::SETTINGS);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_marketing_settings', [], 'settings-' . self::GROUP);
        }

        return $this->pages->done($request, $this->page->resetGroup(self::GROUP, $this->pages->actor()), 'cattophp_marketing_settings', [], 'settings-' . self::GROUP);
    }

    public function reset(Request $request, string $name): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::SETTINGS);
        if (!$this->page->inGroup(self::GROUP, $name)) {
            throw new NotFoundHttpException('There is no such setting.');
        }
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_marketing_settings', [], 'settings-' . self::GROUP);
        }

        return $this->pages->done($request, $this->page->reset(self::GROUP, $name, $this->pages->actor()), 'cattophp_marketing_settings', [], 'settings-' . self::GROUP);
    }

    public function addChannel(Request $request): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::SETTINGS);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_marketing_settings', [], 'channel-add');
        }
        try {
            $channel = $this->channels->add(MarketingPages::text($request->request->get('label', '')), MarketingPages::text($request->request->get('link_prefix', '')),
                MarketingPages::text($request->request->get('icon', '')), $this->pages->actor());
        } catch (MarketingRefused $refused) {
            // A key made from the name that is taken is the name's fault, beside the name.
            $errors = $refused->errors();
            if (isset($errors['key'])) {
                $errors['label'] = trim(($errors['label'] ?? '') . ' ' . $errors['key']);
                unset($errors['key']);
            }

            return $this->pages->refused($request, CampaignsController::why(new MarketingRefused($errors), 'The channel was not added'), 'cattophp_marketing_settings', [], 'channel-add', errors: $errors);
        }

        return $this->pages->done($request, $channel->label . ' is added to the channels, last in the list.', 'cattophp_marketing_settings', [], 'channels');
    }

    public function saveChannel(Request $request, string $key): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::SETTINGS);
        $channel = $this->channel($key);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_marketing_settings', ['channel' => $channel->key], 'channel-edit');
        }
        try {
            $changed = $this->channels->change($channel->key, MarketingPages::text($request->request->get('label', '')), MarketingPages::text($request->request->get('link_prefix', '')),
                MarketingPages::text($request->request->get('icon', '')), $this->pages->actor());
        } catch (MarketingRefused $refused) {
            return $this->pages->refused($request, CampaignsController::why($refused, $channel->label . ' was not saved'), 'cattophp_marketing_settings', ['channel' => $channel->key], 'channel-edit', errors: $refused);
        }

        return $this->pages->done($request, $changed->label === $channel->label ? $changed->label . ' is saved.' : $channel->label . ' is now called ' . $changed->label . '.', 'cattophp_marketing_settings', [], 'channels');
    }

    public function moveChannel(Request $request, string $key): RedirectResponse
    {
        $direction = MarketingPages::text($request->request->get('direction', ''));
        if (!in_array($direction, ['up', 'down'], true)) {
            throw new NotFoundHttpException('A channel moves up or down.');
        }

        return $this->act($request, $key, function (Channel $channel) use ($direction): string {
            $this->channels->move($channel->key, $direction === 'up' ? -1 : 1, $this->pages->actor());

            return $channel->label . ' is moved ' . $direction . '.';
        });
    }

    public function retireChannel(Request $request, string $key): RedirectResponse
    {
        return $this->act($request, $key, function (Channel $channel): string {
            $this->channels->retire($channel->key, $this->pages->actor());

            return $channel->label . ' is retired: no longer offered for new publications. Its publications keep it.';
        });
    }

    public function restoreChannel(Request $request, string $key): RedirectResponse
    {
        return $this->act($request, $key, function (Channel $channel): string {
            $this->channels->restore($channel->key, $this->pages->actor());

            return $channel->label . ' is offered for new publications again.';
        });
    }

    public function deleteChannel(Request $request, string $key): RedirectResponse
    {
        return $this->act($request, $key, function (Channel $channel): string {
            $this->channels->delete($channel->key, $this->pages->actor());

            return $channel->label . ' is deleted.';
        });
    }

    public function addSite(Request $request): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::SETTINGS);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_marketing_settings', [], 'site-add');
        }
        try {
            $site = $this->sites->add(MarketingPages::text($request->request->get('name', '')), MarketingPages::text($request->request->get('address', '')),
                MarketingPages::text($request->request->get('secret', '')), $this->pages->actor());
        } catch (MarketingRefused $refused) {
            return $this->pages->refused($request, CampaignsController::why($refused, 'The site was not connected'), 'cattophp_marketing_settings', [], 'site-add', errors: $refused);
        }

        return $this->pages->done($request, $site->name . ' is added. Put the same shared secret in on its own Connected sites card, then press Check the connection.',
            'cattophp_marketing_settings', [], 'site-' . $site->key);
    }

    public function saveSite(Request $request, string $key): RedirectResponse
    {
        return $this->onSite($request, $key, 'site-' . $key . '-change', function (Site $site) use ($request): string {
            $changed = $this->sites->change($site->key, MarketingPages::text($request->request->get('name', '')), MarketingPages::text($request->request->get('address', '')), $this->pages->actor());

            return $changed->name . ' is saved.';
        }, 'was not saved');
    }

    public function checkSite(Request $request, string $key): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::SETTINGS);
        $site = $this->site($key);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_marketing_settings', [], 'site-' . $site->key);
        }
        $problem = $this->reports->check($site, $this->pages->actor());
        if ($problem !== null) {
            // One sentence: the problem's own first word goes on in small letters after the colon.
            return $this->pages->refused($request, $site->name . ' is not connected: ' . lcfirst($problem), 'cattophp_marketing_settings', [], 'site-' . $site->key);
        }

        return $this->pages->done($request, $site->name . ' is connected.', 'cattophp_marketing_settings', [], 'site-' . $site->key);
    }

    public function changeSiteSecret(Request $request, string $key): RedirectResponse
    {
        return $this->onSite($request, $key, 'site-' . $key . '-secret', function (Site $site) use ($request): string {
            $this->sites->changeSecret($site->key, MarketingPages::text($request->request->get('secret', '')), $this->pages->actor());

            return $site->name . ' has its new shared secret. The previous one is still accepted until ' . $site->name . ' uses the new one: put it in there too.';
        }, 'kept its shared secret');
    }

    public function sendSiteAgain(Request $request, string $key): RedirectResponse
    {
        return $this->onSite($request, $key, 'site-' . $key, function (Site $site): string {
            $count = $this->reports->sendAgain($site);

            return $count === 0 ? 'No report to ' . $site->name . ' was waiting to be sent again.' : ucfirst(Sites::reports($count)) . ' to ' . $site->name . ' will be sent again within a minute.';
        }, 'was not changed');
    }

    public function removeSite(Request $request, string $key): RedirectResponse
    {
        return $this->onSite($request, $key, 'site-' . $key, function (Site $site) use ($request): string {
            $dropped = $this->sites->remove($site->key, MarketingPages::text($request->request->get('drop', '')) === 'yes', $this->pages->actor());

            return $site->name . ' is no longer connected' . ($dropped > 0 ? ', and ' . Sites::reports($dropped) . ' to it ' . ($dropped === 1 ? 'was' : 'were') . ' dropped' : '')
                . '. Its touches and sales keep its name.';
        }, 'is still connected', MarketingSubjectLinks::SITES_ANCHOR);
    }

    /** @param callable(Site): string $change */
    private function onSite(Request $request, string $key, string $fragment, callable $change, string $notDone, string $doneFragment = ''): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::SETTINGS);
        $site = $this->site($key);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_marketing_settings', [], $fragment);
        }
        try {
            $done = $change($site);
        } catch (MarketingRefused $refused) {
            return $this->pages->refused($request, CampaignsController::why($refused, $site->name . ' ' . $notDone), 'cattophp_marketing_settings', [], $fragment, errors: $refused);
        }

        return $this->pages->done($request, $done, 'cattophp_marketing_settings', [], $doneFragment !== '' ? $doneFragment : 'site-' . $site->key);
    }

    private function site(string $key): Site
    {
        return $this->sites->get($key) ?? throw new NotFoundHttpException('There is no such connected site.');
    }

    /**
     * The Connected sites card: each site as its card shows it, with its forms, and the form to add one. Make a secret
     * (?make_secret=new, or a site's key) fills the form's secret with a new one, shown this once to copy.
     *
     * @param array<string,string> $errors
     * @return array<string,mixed>
     */
    private function connectedSites(Request $request, array $errors): array
    {
        $make = MarketingPages::text($request->query->get('make_secret', ''));
        $addAction = $this->pages->path('cattophp_marketing_site_add');
        $added = TypedForm::take($request, $addAction);
        $rows = [];
        foreach ($this->sites->all() as $site) {
            $rows[] = $this->siteRow($request, $site, $errors, $make === $site->key ? Sites::makeSecret() : '');
        }
        $made = $make === 'new' ? Sites::makeSecret() : '';

        return [
            'can_send' => $this->reports->canSend(),
            'sites' => $rows,
            'add' => [
                'action' => $addAction,
                'name' => MarketingPages::text($added['name'] ?? ''),
                'address' => MarketingPages::text($added['address'] ?? ''),
                'secret' => $made,
                'made' => $made !== '',
                'make_href' => $this->pages->path('cattophp_marketing_settings', ['make_secret' => 'new']) . '#site-add',
                'errors' => ($added === [] ? [] : $errors) + ['name' => '', 'address' => '', 'secret' => ''],
                'open' => $added !== [] || $made !== '' || $rows === [],
            ],
        ];
    }

    /**
     * @param array<string,string> $errors
     * @return array<string,mixed> a connected site as its card shows it
     */
    private function siteRow(Request $request, Site $site, array $errors, string $made): array
    {
        $parameters = ['key' => $site->key];
        $counts = $this->reports->counts($site);
        $saveAction = $this->pages->path('cattophp_marketing_site_save', $parameters);
        $secretAction = $this->pages->path('cattophp_marketing_site_secret', $parameters);
        $changed = TypedForm::take($request, $saveAction);
        $secretTyped = TypedForm::take($request, $secretAction);
        [$state, $tone] = match (true) {
            $site->isConnected() => ['Connected', 'success'],
            $site->lastProblem !== null => ['Not connected', 'danger'],
            default => ['Not checked yet', 'warning'],
        };
        $facts = [['label' => 'Address', 'value' => $site->address],
            ['label' => 'Shared secret', 'value' => Sites::SECRET_SHOWN . ($site->isChangingSecret() ? ' (a new one: the previous one is still accepted until ' . $site->name . ' uses it)' : '')]];
        $facts[] = ['label' => 'Last checked', 'value' => $site->lastCheckedAt === null ? 'Never' : $this->marketing->moment($site->lastCheckedAt)];
        if ($site->clockApartSeconds !== null) {
            $facts[] = ['label' => 'Clocks', 'value' => abs($site->clockApartSeconds) < 60 ? 'Less than a minute apart' : Sites::apart($site->clockApartSeconds) . ' apart'];
        }
        $facts[] = ['label' => 'Reports to it', 'value' => $counts->waiting === 0 ? 'None waiting' : Sites::reports($counts->waiting) . ' waiting'
            . ($counts->nextTry === null ? '' : ', tried next ' . $this->marketing->moment($counts->nextTry))];
        $facts[] = ['label' => 'Last sent to it', 'value' => $site->lastSentAt === null ? 'Never' : $this->marketing->moment($site->lastSentAt)];
        $facts[] = ['label' => 'Last heard from', 'value' => $site->lastReceivedAt === null ? 'Never' : $this->marketing->moment($site->lastReceivedAt)];
        $refused = '';
        if ($site->lastRefused !== null && $site->lastRefusedAt !== null) {
            [$what, $why] = array_pad(explode(': ', $site->lastRefused, 2), 2, '');
            $refused = 'Last refused ' . $what . ', ' . $this->marketing->moment($site->lastRefusedAt) . ': ' . $why;
        }

        return [
            'key' => $site->key,
            'name' => $site->name,
            'address' => $site->address,
            'state' => $state,
            'tone' => $tone,
            'connected' => $site->isConnected(),
            'facts' => $facts,
            'problem' => $site->lastProblem === null || $site->lastProblemAt === null ? '' : $site->lastProblem . ' (' . $this->marketing->moment($site->lastProblemAt) . ')',
            'refused' => $refused,
            'given_up' => $counts->givenUp === 0 ? '' : ucfirst(Sites::reports($counts->givenUp)) . ' could not be delivered.',
            'undelivered' => $counts->undelivered() === 0 ? '' : ucfirst(Sites::reports($counts->undelivered())) . ' to it ' . ($counts->undelivered() === 1 ? 'is' : 'are') . ' not delivered yet.',
            'check_action' => $this->pages->path('cattophp_marketing_site_check', $parameters),
            'send_action' => $this->pages->path('cattophp_marketing_site_send', $parameters),
            'remove_action' => $this->pages->path('cattophp_marketing_site_remove', $parameters),
            'change' => [
                'action' => $saveAction,
                'name' => MarketingPages::text($changed['name'] ?? $site->name),
                'address' => MarketingPages::text($changed['address'] ?? $site->address),
                'errors' => ($changed === [] ? [] : $errors) + ['name' => '', 'address' => ''],
                'open' => $changed !== [],
            ],
            'secret' => [
                'action' => $secretAction,
                'value' => $made,
                'made' => $made !== '',
                'make_href' => $this->pages->path('cattophp_marketing_settings', ['make_secret' => $site->key]) . '#site-' . $site->key . '-secret',
                'errors' => ($secretTyped === [] ? [] : $errors) + ['secret' => ''],
                'open' => $secretTyped !== [] || $made !== '',
            ],
        ];
    }

    /** @param callable(Channel): string $change */
    private function act(Request $request, string $key, callable $change): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::SETTINGS);
        $channel = $this->channel($key);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_marketing_settings', [], 'channels');
        }
        try {
            $done = $change($channel);
        } catch (MarketingRefused $refused) {
            return $this->pages->refused($request, $refused->getMessage(), 'cattophp_marketing_settings', [], 'channels');
        }

        return $this->pages->done($request, $done, 'cattophp_marketing_settings', [], 'channels');
    }

    private function channel(string $key): Channel
    {
        return $this->channels->get($key) ?? throw new NotFoundHttpException('There is no such channel.');
    }

    /** @return array<string,mixed> a channel as a row of the list */
    private function channelRow(Channel $channel, int $index, int $count): array
    {
        $parameters = ['key' => $channel->key];

        return [
            'key' => $channel->key,
            'label' => $channel->label,
            'prefix' => $channel->linkPrefix,
            'icon' => $this->marketing->icon($channel->icon),
            'publications' => number_format($channel->publications),
            'active' => $channel->active,
            'in_use' => $channel->inUse(),
            'first' => $index === 0,
            'last' => $index === $count - 1,
            'edit_href' => $this->pages->path('cattophp_marketing_settings', ['channel' => $channel->key]) . '#channel-edit',
            'move_action' => $this->pages->path('cattophp_marketing_channel_move', $parameters),
            'retire_action' => $this->pages->path($channel->active ? 'cattophp_marketing_channel_retire' : 'cattophp_marketing_channel_restore', $parameters),
            'delete_action' => $this->pages->path('cattophp_marketing_channel_delete', $parameters),
        ];
    }

    /** @return list<array{value:string,label:string,icon:string}> the icons a channel may have, as feature cards, its own first if it is not one of them */
    private function iconCards(string $current): array
    {
        $names = self::ICONS;
        if ($current !== '' && !in_array($current, $names, true)) {
            array_unshift($names, $current);
        }
        $cards = [];
        foreach ($names as $name) {
            $cards[] = ['value' => $name, 'label' => self::ICON_WORDS[$name] ?? ucfirst(str_replace('-', ' ', $name)), 'icon' => $this->marketing->icon($name)];
        }

        return $cards;
    }
}
