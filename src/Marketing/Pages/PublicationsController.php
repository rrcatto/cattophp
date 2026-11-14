<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Pages;

use CattoPHP\Http\StockPages;
use CattoPHP\Http\TypedForm;
use CattoPHP\Marketing\Advert;
use CattoPHP\Marketing\Adverts;
use CattoPHP\Marketing\Campaign;
use CattoPHP\Marketing\CampaignFigures;
use CattoPHP\Marketing\Channels;
use CattoPHP\Marketing\MarketingPermissions;
use CattoPHP\Marketing\MarketingProject;
use CattoPHP\Marketing\MarketingRefused;
use CattoPHP\Marketing\Period;
use CattoPHP\Marketing\Publication;
use CattoPHP\Marketing\PublicationInput;
use CattoPHP\Marketing\Publications;
use CattoPHP\Marketing\TrackingLinks;
use CattoPHP\Ui\QrCode;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A campaign's publications (`config/routes/marketing.php`): adding one (its channel and advert as feature cards, where
 * exactly, when, where it can be seen, what it cost, its code and where its link leads), and one publication's page: its
 * tracking link ready to copy, the same link with ?src= and with UTM tags, its QR code for a printed advert (shown and
 * downloaded as an SVG file), its clicks, and changing, retiring, restoring or deleting it. Seeing them needs
 * MARKETING.VIEW, changing them MARKETING.CAMPAIGN.MANAGE.
 */
final class PublicationsController
{
    public function __construct(
        private readonly StockPages $pages,
        private readonly MarketingPages $marketing,
        private readonly Publications $publications,
        private readonly Adverts $adverts,
        private readonly Channels $channels,
        private readonly TrackingLinks $links,
        private readonly CampaignFigures $figures,
        private readonly MarketingProject $project,
    ) {
    }

    public function new(Request $request, string $code): Response
    {
        $this->pages->deny(MarketingPermissions::MANAGE);
        $campaign = $this->marketing->campaign($code);
        $action = $this->pages->path('cattophp_marketing_publication_make', ['code' => $campaign->code]);
        $errors = $this->pages->errors($request);
        $typed = TypedForm::take($request, $action);
        $input = $typed === [] ? new PublicationInput(channel: MarketingPages::text($request->query->get('channel', ''))) : PublicationInput::fromForm($typed);

        return $this->pages->page($request, 'marketing-publication-new', '@CattoPHP/marketing/publication.html.twig', [
            'title' => 'Add a publication',
            'lead' => 'Say where you are publishing the campaign, and get a tracking link to use there.',
            'kicker' => $campaign->name,
            'campaign' => $this->campaignFacts($campaign),
            'may_manage' => true,
            'action' => $action,
            'save_label' => 'Save and get its link',
            'publication' => null,
        ] + $this->form($campaign, $input, $errors, null) + ['others' => $this->others($campaign, null)]);
    }

    public function make(Request $request, string $code): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::MANAGE);
        $campaign = $this->marketing->campaign($code);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_marketing_publication_new', ['code' => $campaign->code]);
        }
        try {
            $publication = $this->publications->create($campaign->code, PublicationInput::fromForm($request->request->all()), $this->pages->actor());
        } catch (MarketingRefused $refused) {
            return $this->pages->refused($request, CampaignsController::why($refused, 'The publication was not saved'), 'cattophp_marketing_publication_new', ['code' => $campaign->code], errors: $refused);
        }

        return $this->pages->done($request, $publication->code . ' is saved for ' . $campaign->name . '. Its tracking link is ready to copy.', 'cattophp_marketing_publication',
            ['code' => $campaign->code, 'publication' => $publication->code], 'tracking-link');
    }

    public function show(Request $request, string $code, string $publication): Response
    {
        $this->pages->deny(MarketingPermissions::VIEW);
        $campaign = $this->marketing->campaign($code);
        $found = $this->publication($campaign, $publication);
        $mayManage = $this->pages->may(MarketingPermissions::MANAGE);
        $action = $this->pages->path('cattophp_marketing_publication_save', ['code' => $campaign->code, 'publication' => $found->code]);
        $errors = $this->pages->errors($request);
        $typed = TypedForm::take($request, $action);
        $advert = $found->advertId === null ? null : $this->adverts->byId($found->advertId);
        $input = $typed === [] ? PublicationInput::of($found, $advert) : PublicationInput::fromForm($typed);
        $forms = $this->links->forms($campaign, $found);
        $figures = null;
        foreach ($this->figures->byPublication(Period::all(), $campaign->id) as $row) {
            if ($row->key === $campaign->code . '/' . $found->code) {
                $figures = $row;
            }
        }
        $channel = $this->channels->get($found->channelKey);
        $label = $campaign->name . ', ' . $found->code;

        return $this->pages->page($request, 'marketing-publication', '@CattoPHP/marketing/publication.html.twig', [
            'title' => 'Publication ' . $found->code,
            'lead' => trim(($channel->label ?? $found->channelKey) . ($found->place !== '' ? ', ' . $found->place : '')),
            'kicker' => $campaign->name,
            'campaign' => $this->campaignFacts($campaign),
            'may_manage' => $mayManage,
            'action' => $action,
            'save_label' => 'Save the publication',
            'publication' => [
                'code' => $found->code,
                'retired' => $found->isRetired(),
                'may_delete' => !$this->publications->hasTouches($found),
                'short' => $forms->short,
                'source' => $forms->source,
                'utm' => $forms->utm,
                'qr' => QrCode::svg($forms->short, 'QR code of the tracking link for ' . $label),
                'qr_href' => $this->pages->path('cattophp_marketing_publication_qr', ['code' => $campaign->code, 'publication' => $found->code]),
                'clicks' => number_format($figures->clicks ?? 0),
                'unique_visitors' => number_format($figures->uniqueVisitors ?? 0),
                'set_aside' => number_format($figures->setAside ?? 0),
                'facts' => $this->facts($found, $advert),
                'retire_action' => $this->pages->path('cattophp_marketing_publication_retire', ['code' => $campaign->code, 'publication' => $found->code]),
                'restore_action' => $this->pages->path('cattophp_marketing_publication_restore', ['code' => $campaign->code, 'publication' => $found->code]),
                'delete_action' => $this->pages->path('cattophp_marketing_publication_delete', ['code' => $campaign->code, 'publication' => $found->code]),
            ],
        ] + $this->form($campaign, $input, $errors, $found) + ['others' => $this->others($campaign, $found)]);
    }

    public function save(Request $request, string $code, string $publication): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::MANAGE);
        $campaign = $this->marketing->campaign($code);
        $found = $this->publication($campaign, $publication);
        $parameters = ['code' => $campaign->code, 'publication' => $found->code];
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_marketing_publication', $parameters);
        }
        try {
            $changed = $this->publications->change($campaign->code, $found->code, PublicationInput::fromForm($request->request->all()), $this->pages->actor());
        } catch (MarketingRefused $refused) {
            return $this->pages->refused($request, CampaignsController::why($refused, $found->code . ' was not saved'), 'cattophp_marketing_publication', $parameters, errors: $refused);
        }

        return $this->pages->done($request, $changed->code . ' is saved.', 'cattophp_marketing_publication', ['code' => $campaign->code, 'publication' => $changed->code]);
    }

    public function retire(Request $request, string $code, string $publication): RedirectResponse
    {
        return $this->act($request, $code, $publication, fn (Campaign $campaign, Publication $found): string => $this->publications->retire($campaign->code, $found->code, $this->pages->actor())->code
            . ' is retired. Its link still leads where it did, and its clicks stay with the campaign.');
    }

    public function restore(Request $request, string $code, string $publication): RedirectResponse
    {
        return $this->act($request, $code, $publication, fn (Campaign $campaign, Publication $found): string => $this->publications->restore($campaign->code, $found->code, $this->pages->actor())->code
            . ' is restored.');
    }

    public function delete(Request $request, string $code, string $publication): RedirectResponse
    {
        return $this->act($request, $code, $publication, function (Campaign $campaign, Publication $found): string {
            $this->publications->delete($campaign->code, $found->code, $this->pages->actor());

            return $found->code . ' is deleted from ' . $campaign->name . '.';
        });
    }

    /** The publication's tracking link as a QR code, a file of its own named for the campaign and the publication. */
    public function qr(string $code, string $publication): Response
    {
        $this->pages->deny(MarketingPermissions::VIEW);
        $campaign = $this->marketing->campaign($code);
        $found = $this->publication($campaign, $publication);
        $link = $this->links->forms($campaign, $found)->short;
        $response = new Response(QrCode::document($link, 'QR code of ' . $link));
        $response->headers->set('Content-Type', 'image/svg+xml; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $campaign->code . '-' . $found->code . '-qr.svg'));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function publication(Campaign $campaign, string $code): Publication
    {
        return $this->publications->get($campaign, $code) ?? throw new NotFoundHttpException('There is no such publication.');
    }

    /**
     * A retire, restore or delete, answered with what was done or why not on the page it was asked from: the
     * publication's own (the form sent back=publication) or its campaign's, the campaign's once it is deleted.
     *
     * @param callable(Campaign, Publication): string $change
     */
    private function act(Request $request, string $code, string $publication, callable $change): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::MANAGE);
        $campaign = $this->marketing->campaign($code);
        $found = $this->publication($campaign, $publication);
        $back = $request->request->get('back') === 'publication' ? 'publication' : 'campaign';
        $route = $back === 'publication' ? 'cattophp_marketing_publication' : 'cattophp_marketing_campaign';
        $parameters = $back === 'publication' ? ['code' => $campaign->code, 'publication' => $found->code] : ['code' => $campaign->code];
        $fragment = $back === 'publication' ? '' : 'publications';
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, $route, $parameters, $fragment);
        }
        try {
            $done = $change($campaign, $found);
        } catch (MarketingRefused $refused) {
            return $this->pages->refused($request, $refused->getMessage(), $route, $parameters, $fragment);
        }
        if ($back === 'publication' && $this->publications->get($campaign, $found->code) === null) {
            return $this->pages->done($request, $done, 'cattophp_marketing_campaign', ['code' => $campaign->code], 'publications');
        }

        return $this->pages->done($request, $done, $route, $parameters, $fragment);
    }

    /** @return array<string,string> */
    private function campaignFacts(Campaign $campaign): array
    {
        return ['code' => $campaign->code, 'name' => $campaign->name, 'href' => $this->pages->path('cattophp_marketing_campaign', ['code' => $campaign->code]),
            'destination' => $campaign->destination];
    }

    /**
     * The form as typed, with its channels and adverts as feature cards and why each field was refused.
     *
     * @param array<string,string> $errors
     * @return array<string,mixed>
     */
    private function form(Campaign $campaign, PublicationInput $input, array $errors, ?Publication $publication): array
    {
        $channels = [];
        foreach ($this->channels->all() as $channel) {
            if ($channel->active || $channel->key === $publication?->channelKey) {
                $channels[] = $this->marketing->channelCard($channel) + ['note' => 'Codes ' . $channel->linkPrefix . '1, ' . $channel->linkPrefix . '2' . ($channel->active ? '' : '. Retired')];
            }
        }
        $adverts = [['value' => '', 'label' => 'No advert', 'icon' => $this->marketing->icon('ban'), 'note' => 'Such as a post in your own words']];
        foreach ($this->adverts->list($campaign->id) as $advert) {
            if (!$advert->isRetired() || $advert->id === $publication?->advertId) {
                $adverts[] = ['value' => $advert->reference, 'label' => $advert->name, 'icon' => $this->marketing->icon(self::kindIcon($advert)),
                    'note' => (Advert::KINDS[$advert->kind] ?? $advert->kind) . ($advert->isRetired() ? '. Retired' : '')];
            }
        }

        return [
            'typed' => ['channel' => $input->channel, 'advert' => $input->advert, 'code' => $input->code, 'place' => $input->place, 'published_on' => $input->publishedOn,
                'published_address' => $input->publishedAddress, 'cost' => $input->cost, 'destination' => $input->destination, 'notes' => $input->notes],
            'errors' => $errors + ['channel' => '', 'advert' => '', 'code' => '', 'place' => '', 'published_on' => '', 'published_address' => '', 'cost' => '', 'destination' => '', 'notes' => ''],
            'channel_cards' => $channels,
            'advert_cards' => $adverts,
            'code_fixed' => $publication !== null && $this->publications->hasClicks($publication) ? $publication->code : '',
            'currency' => $this->project->currency(),
            'advert_new_href' => $this->pages->path('cattophp_marketing_advert_new', ['code' => $campaign->code]),
            'back_href' => $this->pages->path('cattophp_marketing_campaign', ['code' => $campaign->code]),
        ];
    }

    /**
     * What a reader without the manage permission is shown of a publication: its facts.
     *
     * @return list<array{label:string,value:string}>
     */
    private function facts(Publication $publication, ?Advert $advert): array
    {
        $channel = $this->channels->get($publication->channelKey);
        $facts = [
            ['label' => 'Channel', 'value' => $channel->label ?? $publication->channelKey],
            ['label' => 'Where exactly', 'value' => $publication->place !== '' ? $publication->place : 'Not given'],
            ['label' => 'Advert', 'value' => $advert->name ?? 'No advert'],
            ['label' => 'Published on', 'value' => $publication->publishedOn !== null ? $this->marketing->day($publication->publishedOn) : 'Not given'],
            ['label' => 'Cost', 'value' => $this->marketing->money($publication->costMinor)],
            ['label' => 'Its link leads to', 'value' => $publication->destination ?? 'Where the campaign\'s links lead'],
        ];
        if ($publication->publishedAddress !== null) {
            $facts[] = ['label' => 'Where it can be seen', 'value' => $publication->publishedAddress];
        }
        if ($publication->notes !== '') {
            $facts[] = ['label' => 'Notes', 'value' => $publication->notes];
        }

        return $facts;
    }

    /**
     * The campaign's other publications, newest first, each with its link.
     *
     * @return list<array<string,string>>
     */
    private function others(Campaign $campaign, ?Publication $except): array
    {
        $rows = [];
        foreach (array_reverse($this->publications->list($campaign->id)) as $publication) {
            if ($publication->id === $except?->id) {
                continue;
            }
            $rows[] = [
                'code' => $publication->code,
                'day' => $this->marketing->day($publication->publishedOn ?? $publication->createdAt),
                'channel' => $this->channels->get($publication->channelKey)->label ?? $publication->channelKey,
                'place' => $publication->place,
                'link' => $this->links->forms($campaign, $publication)->short,
                'href' => $this->pages->path('cattophp_marketing_publication', ['code' => $campaign->code, 'publication' => $publication->code]),
            ];
        }

        return $rows;
    }

    private static function kindIcon(Advert $advert): string
    {
        return match ($advert->kind) {
            'picture' => 'image',
            'video' => 'video',
            default => 'type',
        };
    }
}
