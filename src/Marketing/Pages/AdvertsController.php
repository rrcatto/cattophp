<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Pages;

use CattoPHP\Http\StockPages;
use CattoPHP\Http\TypedForm;
use CattoPHP\Marketing\Advert;
use CattoPHP\Marketing\AdvertInput;
use CattoPHP\Marketing\Pictures;
use CattoPHP\Marketing\Adverts;
use CattoPHP\Marketing\Campaign;
use CattoPHP\Marketing\MarketingPermissions;
use CattoPHP\Marketing\MarketingRefused;
use CattoPHP\Marketing\MarketingSettings;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A campaign's adverts (`config/routes/marketing.php`): adding one (its name, kind as feature cards, headline, words, a
 * picture uploaded, an outside address such as a video's, notes), one advert's page to change it, retiring, restoring
 * and deleting it, and its picture, shown only to someone who may see the Campaign Marketing Manager. Seeing them needs
 * MARKETING.VIEW, changing them MARKETING.CAMPAIGN.MANAGE.
 */
final class AdvertsController
{
    /** Each kind of advert, the icon of its card and what it says. */
    private const KINDS = [
        'words' => ['type', 'A headline and words, such as a post'],
        'picture' => ['image', 'A picture, with words if you like'],
        'video' => ['video', 'A video on another site, by its address'],
    ];

    public function __construct(
        private readonly StockPages $pages,
        private readonly MarketingPages $marketing,
        private readonly Adverts $adverts,
        private readonly MarketingSettings $settings,
    ) {
    }

    public function new(Request $request, string $code): Response
    {
        $this->pages->deny(MarketingPermissions::MANAGE);
        $campaign = $this->marketing->campaign($code);
        $action = $this->pages->path('cattophp_marketing_advert_make', ['code' => $campaign->code]);
        $errors = $this->pages->errors($request);
        $typed = TypedForm::take($request, $action);

        return $this->pages->page($request, 'marketing-advert-new', '@CattoPHP/marketing/advert.html.twig', [
            'title' => 'Add an advert',
            'lead' => 'The words, picture or video this campaign uses, so each publication can say which it showed.',
            'kicker' => $campaign->name,
            'may_manage' => true,
            'action' => $action,
            'save_label' => 'Save the advert',
            'advert' => null,
        ] + $this->form($campaign, $typed === [] ? new AdvertInput() : AdvertInput::fromForm($typed), $errors, null));
    }

    public function make(Request $request, string $code): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::MANAGE);
        $campaign = $this->marketing->campaign($code);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_marketing_advert_new', ['code' => $campaign->code]);
        }
        try {
            $advert = $this->adverts->create($campaign->code, AdvertInput::fromForm($request->request->all(), $this->upload($request)), $this->pages->actor());
        } catch (MarketingRefused $refused) {
            return $this->pages->refused($request, CampaignsController::why($refused, 'The advert was not saved'), 'cattophp_marketing_advert_new', ['code' => $campaign->code], errors: $refused);
        }

        return $this->pages->done($request, 'The advert ' . $advert->name . ' is saved for ' . $campaign->name . '.', 'cattophp_marketing_campaign', ['code' => $campaign->code], 'adverts');
    }

    public function show(Request $request, string $code, string $reference): Response
    {
        $this->pages->deny(MarketingPermissions::VIEW);
        $campaign = $this->marketing->campaign($code);
        $advert = $this->advert($campaign, $reference);
        $parameters = ['code' => $campaign->code, 'reference' => $advert->reference];
        $action = $this->pages->path('cattophp_marketing_advert_save', $parameters);
        $errors = $this->pages->errors($request);
        $typed = TypedForm::take($request, $action);

        return $this->pages->page($request, 'marketing-advert', '@CattoPHP/marketing/advert.html.twig', [
            'title' => $advert->name,
            'lead' => (Advert::KINDS[$advert->kind] ?? $advert->kind) . ' advert, ' . ($advert->publications === 0 ? 'not published yet.' : 'in ' . MarketingPages::count($advert->publications, 'publication', 'publications') . '.'),
            'kicker' => $campaign->name,
            'may_manage' => $this->pages->may(MarketingPermissions::MANAGE),
            'action' => $action,
            'save_label' => 'Save the advert',
            'advert' => [
                'reference' => $advert->reference,
                'name' => $advert->name,
                'retired' => $advert->isRetired(),
                'may_delete' => !$advert->inUse(),
                'picture' => $advert->picture === null ? '' : $this->pages->path('cattophp_marketing_advert_picture', $parameters + ['v' => substr($advert->picture, 0, 12)]),
                'facts' => [
                    ['label' => 'Kind', 'value' => Advert::KINDS[$advert->kind] ?? $advert->kind],
                    ['label' => 'Headline', 'value' => $advert->headline !== '' ? $advert->headline : 'None'],
                    ['label' => 'Words', 'value' => $advert->words !== '' ? $advert->words : 'None'],
                    ['label' => 'Address', 'value' => $advert->mediaAddress ?? 'None'],
                    ['label' => 'Notes', 'value' => $advert->notes !== '' ? $advert->notes : 'None'],
                    ['label' => 'Made', 'value' => $this->marketing->day($advert->createdAt)],
                ],
                'retire_action' => $this->pages->path('cattophp_marketing_advert_retire', $parameters),
                'restore_action' => $this->pages->path('cattophp_marketing_advert_restore', $parameters),
                'delete_action' => $this->pages->path('cattophp_marketing_advert_delete', $parameters),
            ],
        ] + $this->form($campaign, $typed === [] ? AdvertInput::of($advert) : AdvertInput::fromForm($typed), $errors, $advert));
    }

    public function save(Request $request, string $code, string $reference): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::MANAGE);
        $campaign = $this->marketing->campaign($code);
        $advert = $this->advert($campaign, $reference);
        $parameters = ['code' => $campaign->code, 'reference' => $advert->reference];
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_marketing_advert', $parameters);
        }
        try {
            $changed = $this->adverts->change($advert->reference, AdvertInput::fromForm($request->request->all(), $this->upload($request)), $this->pages->actor(), $campaign);
        } catch (MarketingRefused $refused) {
            return $this->pages->refused($request, CampaignsController::why($refused, 'The advert was not saved'), 'cattophp_marketing_advert', $parameters, errors: $refused);
        }

        return $this->pages->done($request, 'The advert ' . $changed->name . ' is saved.', 'cattophp_marketing_advert', $parameters);
    }

    public function retire(Request $request, string $code, string $reference): RedirectResponse
    {
        return $this->act($request, $code, $reference, fn (Campaign $campaign, Advert $advert): string => 'The advert ' . $this->adverts->retire($advert->reference, $this->pages->actor(), $campaign)->name
            . ' is retired: kept with its publications, and no longer offered for new ones.');
    }

    public function restore(Request $request, string $code, string $reference): RedirectResponse
    {
        return $this->act($request, $code, $reference, fn (Campaign $campaign, Advert $advert): string => 'The advert ' . $this->adverts->restore($advert->reference, $this->pages->actor(), $campaign)->name . ' is restored.');
    }

    public function delete(Request $request, string $code, string $reference): RedirectResponse
    {
        return $this->act($request, $code, $reference, function (Campaign $campaign, Advert $advert): string {
            $this->adverts->delete($advert->reference, $this->pages->actor(), $campaign);

            return 'The advert ' . $advert->name . ' is deleted.';
        });
    }

    /** An advert's picture, for someone who may see the Campaign Marketing Manager; anyone else is refused. */
    public function picture(string $code, string $reference): Response
    {
        $this->pages->deny(MarketingPermissions::VIEW);
        $campaign = $this->marketing->campaign($code);
        $picture = $this->adverts->picture($reference, $campaign) ?? throw new NotFoundHttpException('This advert has no picture.');
        $response = new BinaryFileResponse($picture->path, 200, ['Content-Type' => $picture->mimeType]);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $picture->name);
        // Its address changes with the picture, so a browser may keep it; never a shared cache.
        $response->headers->set('Cache-Control', 'private, max-age=86400');

        return $response;
    }

    private function advert(Campaign $campaign, string $reference): Advert
    {
        return $this->adverts->get($reference, $campaign) ?? throw new NotFoundHttpException('There is no such advert.');
    }

    private function upload(Request $request): ?UploadedFile
    {
        $file = $request->files->get('picture');

        return $file instanceof UploadedFile ? $file : null;
    }

    /**
     * A retire, restore or delete, answered with what was done or why not on the page it was asked from: the advert's
     * own (the form sent back=advert) or its campaign's, the campaign's once it is deleted.
     *
     * @param callable(Campaign, Advert): string $change
     */
    private function act(Request $request, string $code, string $reference, callable $change): RedirectResponse
    {
        $this->pages->deny(MarketingPermissions::MANAGE);
        $campaign = $this->marketing->campaign($code);
        $advert = $this->advert($campaign, $reference);
        $own = $request->request->get('back') === 'advert';
        $route = $own ? 'cattophp_marketing_advert' : 'cattophp_marketing_campaign';
        $parameters = $own ? ['code' => $campaign->code, 'reference' => $advert->reference] : ['code' => $campaign->code];
        $fragment = $own ? '' : 'adverts';
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, $route, $parameters, $fragment);
        }
        try {
            $done = $change($campaign, $advert);
        } catch (MarketingRefused $refused) {
            return $this->pages->refused($request, $refused->getMessage(), $route, $parameters, $fragment);
        }
        if ($own && $this->adverts->get($advert->reference, $campaign) === null) {
            return $this->pages->done($request, $done, 'cattophp_marketing_campaign', ['code' => $campaign->code], 'adverts');
        }

        return $this->pages->done($request, $done, $route, $parameters, $fragment);
    }

    /**
     * The form as typed, its kinds as feature cards, and why each field was refused.
     *
     * @param array<string,string> $errors
     * @return array<string,mixed>
     */
    private function form(Campaign $campaign, AdvertInput $input, array $errors, ?Advert $advert): array
    {
        $kinds = [];
        foreach (self::KINDS as $value => [$icon, $note]) {
            $kinds[] = ['value' => $value, 'label' => Advert::KINDS[$value], 'icon' => $this->marketing->icon($icon), 'note' => $note];
        }

        return [
            'campaign' => ['code' => $campaign->code, 'name' => $campaign->name, 'href' => $this->pages->path('cattophp_marketing_campaign', ['code' => $campaign->code])],
            'typed' => ['name' => $input->name, 'kind' => $input->kind, 'headline' => $input->headline, 'words' => $input->words, 'media_address' => $input->mediaAddress, 'notes' => $input->notes],
            'errors' => $errors + ['name' => '', 'kind' => '', 'headline' => '', 'words' => '', 'media_address' => '', 'picture' => '', 'notes' => ''],
            'kind_cards' => $kinds,
            'has_picture' => $advert?->picture !== null,
            'largest' => Pictures::size($this->settings->pictureLargest()),
            'back_href' => $this->pages->path('cattophp_marketing_campaign', ['code' => $campaign->code]) . '#adverts',
        ];
    }
}
