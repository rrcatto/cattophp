<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

use CattoPHP\Http\ContentSecurityPolicy;
use CattoPHP\Installation\Installation;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Destination;
use CattoPHP\Marketing\Landing\Kinds\VideoKind;
use CattoPHP\Marketing\MarketingProject;
use CattoPHP\Marketing\NoProject;
use CattoPHP\Marketing\Offer;
use CattoPHP\Marketing\Rows;
use CattoPHP\Theme\PageRenderer;
use CattoPHP\Ui\HalfRows;
use Twig\Environment;

/**
 * Draws a landing page: one drawing for the public page, the editor's preview, a kept version and one section's preview,
 * so they never differ. Everything a section shows is resolved here first (the offers' cards and what the reader can do,
 * pictures, where each button leads, the countdown's moment, whether a video may be framed), so the templates query
 * nothing. The head (title, description, canonical address, robots, Open Graph, share picture) is worked out here too, and
 * the project's LandingProject::page() adds to or replaces it.
 *
 * The public methods, kept stable for the editor and for projects:
 *
 *   forVisitor(LandingPage $page, ?string $account = null): ?LandingView
 *       The page as visitors see it now, for the reader (their account as the activity log names it, or none); null when
 *       visitors cannot see it now (a draft, an offer that is not public, a campaign that is a draft or sends them elsewhere).
 *   preview(LandingPage $page, string $back = '', ?string $account = null): LandingView
 *       The saved page as visitors will see it, for its editor ($back: the editor's address): its buttons do nothing,
 *       nothing is recorded, noindex and nofollow, no canonical address and no share card.
 *   version(LandingPage $page, LandingVersion $version, string $back = '', ?string $account = null): ?LandingView
 *       A kept version drawn as a preview, with its notice; null for a version holding no content (a page taken down).
 *   sectionPreview(LandingPage $page, string $sectionReference, ?string $account = null): ?LandingView
 *       One section alone as a preview, shown or hidden (the hero as the head); null when it is not the page's, its copy no
 *       longer passes or it has nothing to draw.
 *   data(LandingView $view): array<string,mixed>
 *       What @CattoPHP/marketing/landing/page.html.twig reads: title, page_meta and landing, with the project's additions.
 *   draw(LandingView $view): string
 *       The whole page in the project's frame (its theme through the page renderer, as the page landing-page, else its layout).
 *   drawSection(LandingView $view): string
 *       The view's notice, hero and sections alone, without the frame: what htmx swaps into the editor's preview pane.
 *   press(LandingView $view, string $press): ?array{href:string,offer:?string,action:string,section:string}
 *       Where a GET button's /go/{press} address leads on this view, or null when the view has no such button.
 */
final class LandingViewBuilder
{
    public const TEMPLATE = '@CattoPHP/marketing/landing/page.html.twig';

    /** The page's name for the page renderer and a project's PageProject. */
    public const PAGE = 'landing-page';

    /** The icon of each kind of action when the project gives none. */
    public const ICONS = ['view' => 'action-view', 'favourite' => 'action-favourite', 'buy' => 'action-buy', 'request' => 'action-request', 'continue' => 'action-continue',
        'order' => 'action-orders', 'sign_in' => 'action-sign-in'];

    /** A press's name in its /go/{press} address: the section's reference and the button's number in it. */
    public const PRESS = '/^[a-z0-9]{4,12}-[1-9][0-9]{0,2}$/';

    public function __construct(
        private readonly LandingPages $pages,
        private readonly SectionKinds $kinds,
        private readonly Campaigns $campaigns,
        private readonly LandingProject $landing = new GenericLanding(),
        private readonly MarketingProject $project = new NoProject(),
        private readonly ?PictureSources $pictures = null,
        private readonly ?Installation $installation = null,
        private readonly ?ContentSecurityPolicy $policy = null,
        private readonly ?Environment $twig = null,
        private readonly ?PageRenderer $renderer = null,
    ) {
    }

    /** The page as visitors see it now, for the reader; null when visitors cannot see it now. */
    public function forVisitor(LandingPage $page, ?string $account = null): ?LandingView
    {
        if (!$page->isPublished()) {
            return null;
        }
        $context = $this->pages->context($page);
        if ($page->isOfferPage() && !($context->offer()->public ?? false)) {
            return null;
        }
        $state = NotRunning::for($page, $context->campaign, $context->now);
        if ($page->isCampaignPage() && !$state->draws()) {
            return null;
        }
        $path = $this->pages->address($page);

        return $this->build($page, $context, $state, new LandingVisit($account, false, $path, $page->reference), $this->pages->sections($page, $context));
    }

    /** The saved page as visitors will see it, for its editor: inert, recorded nowhere, claiming no address. */
    public function preview(LandingPage $page, string $back = '', ?string $account = null): LandingView
    {
        [$context, $state] = $this->previewContext($page);
        $public = $page->isPublished() && ($page->isOfferPage() ? ($context->offer()->public ?? false) : $state->draws());
        $notice = 'This is the saved page as visitors will see it' . ($public ? '' : ' once it is published') . '. Its buttons do nothing here and this view is not counted.';

        return $this->build($page, $context, $state, LandingVisit::preview($page, $this->pages->address($page), $account), $this->pages->sections($page, $context), $notice, $back);
    }

    /** A kept version drawn as a preview, with its notice; null for a version holding no content. */
    public function version(LandingPage $page, LandingVersion $version, string $back = '', ?string $account = null): ?LandingView
    {
        if (!$version->isPublished() || $version->content === null || $version->pageId !== $page->id) {
            return null;
        }
        $settings = $version->settings();
        $share = LandingPages::picture(is_array($settings['share_picture'] ?? null) ? $settings['share_picture'] : null);
        $kept = new LandingPage($page->id, $page->reference, $page->campaignId, $page->offerType, $page->offerReference, $page->slug, $page->status, $page->firstPublishedAt,
            $page->publishedAt, self::nullable($settings['meta_title'] ?? null), self::nullable($settings['meta_description'] ?? null), $share,
            is_string($settings['not_running'] ?? null) ? $settings['not_running'] : $page->notRunning, self::nullable($settings['not_running_to'] ?? null), $page->createdBy, $page->updatedBy,
            $page->createdAt, $page->updatedAt);
        [$context, $state] = $this->previewContext($kept);
        $sections = [];
        foreach ($version->sections() as $number => $stored) {
            $kind = $this->kinds->get(Rows::text($stored['kind'] ?? ''));
            if ($kind === null) {
                continue;
            }
            try {
                $data = $kind->normalise((is_array($stored['data'] ?? null) ? $stored['data'] : []) + $kind->defaults(), $context);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $sections[] = new LandingSection(0, Rows::text($stored['reference'] ?? '') !== '' ? Rows::text($stored['reference']) : 'v' . $number, $page->id, $kind->key(), $number,
                Rows::bool($stored['enabled'] ?? true), Rows::text($stored['width'] ?? 'full') === 'half' ? 'half' : 'full',
                array_key_exists(Rows::text($stored['shown_when'] ?? ''), LandingSection::SHOWN_WHEN) ? Rows::text($stored['shown_when']) : 'always', $data, $kind, '', false, $version->at,
                $version->at);
        }
        $zone = $this->campaigns->zone();
        $from = $version->at->setTimezone($zone)->format('j M Y, H:i');
        $notice = ($version->until === null ? 'What visitors see now, since ' . $from . '.' : 'What visitors saw from ' . $from . ' to ' . $version->until->setTimezone($zone)->format('j M Y, H:i') . '.')
            . ' Prices and the ' . mb_strtolower($this->project->offersHeading()) . ' are shown as they are today, because the page reads them live.';

        return $this->build($kept, $context, $state, LandingVisit::preview($page, $this->pages->address($page), $account), $sections, $notice, $back);
    }

    /** One section alone as a preview, shown or hidden; null when it is not the page's, no longer passes or draws nothing. */
    public function sectionPreview(LandingPage $page, string $sectionReference, ?string $account = null): ?LandingView
    {
        [$context, $state] = $this->previewContext($page);
        foreach ($this->pages->sections($page, $context) as $section) {
            if ($section->reference !== strtolower(trim($sectionReference))) {
                continue;
            }
            if (!$section->isValid()) {
                return null;
            }
            $view = $this->build($page, $context, $state, LandingVisit::preview($page, $this->pages->address($page), $account), [$section], '', '', true);

            return $view->sections !== [] || $section->isHero() ? $view : null;
        }

        return null;
    }

    /**
     * What the page's template reads: its title, its head as page_meta (title, description, canonical, robots, og,
     * twitter_card), the landing page itself, and what the project adds (LandingProject::page(): its own head, its menu's
     * mark, what to load), which wins.
     *
     * @return array<string,mixed>
     */
    public function data(LandingView $view): array
    {
        $data = ['title' => Rows::text($view->meta['title'] ?? ''), 'lead' => '', 'page_meta' => $view->meta, 'landing' => $view->toArray()];

        return array_replace($data, $this->landing->page($view));
    }

    /** The whole page in the project's frame: its theme through the page renderer, else its layout. */
    public function draw(LandingView $view): string
    {
        $data = $this->data($view);
        if ($this->renderer !== null) {
            return $this->renderer->renderTemplate('landing-page', self::TEMPLATE, $data);
        }

        return $this->twig()->render(self::TEMPLATE, $data);
    }

    /** The view's notice, hero and sections without the frame, for the editor's preview pane. */
    public function drawSection(LandingView $view): string
    {
        return $this->twig()->load(self::TEMPLATE)->renderBlock('landing_content', $this->data($view));
    }

    /**
     * Where a GET button's /go/{press} address leads on this view, or null when the view has no such button.
     *
     * @return array{href:string,offer:?string,action:string,section:string}|null
     */
    public function press(LandingView $view, string $press): ?array
    {
        return $view->presses[$press] ?? null;
    }

    /**
     * The page's context and state for a preview: as visitors would see it now, except that a draft campaign's page is
     * shown as it will be while the campaign runs.
     *
     * @return array{0:LandingContext,1:NotRunning}
     */
    private function previewContext(LandingPage $page): array
    {
        $context = $this->pages->context($page);
        $state = NotRunning::for($page, $context->campaign, $context->now);
        if ($state->state === 'draft') {
            $context = new LandingContext($context->kind, $context->campaign, true, $context->offers, $context->now, $context->page, $context->kindsOnPage, $context->offersHeading,
                $context->offerWord, $context->promotion);
            $state = NotRunning::for($page, null, $context->now);
        }

        return [$context, $state];
    }

    /**
     * The drawing itself: the sections shown now (all given, when $all), each with its kind's view and what the builder
     * resolves; the hero; the rows; the head.
     *
     * @param list<LandingSection> $sections
     */
    private function build(LandingPage $page, LandingContext $context, NotRunning $state, LandingVisit $visit, array $sections, string $notice = '', string $back = '', bool $all = false): LandingView
    {
        $path = $this->pages->address($page);
        $offer = $page->isOfferPage() ? $context->offer() : null;
        $card = $offer === null ? null : $this->landing->card($offer, $visit, new CardOptions([], false, null, $context->running));
        $presses = [];
        $hero = null;
        $drawn = [];
        $needs = [];
        $counts = [];
        foreach ($sections as $section) {
            if (!$section->isValid() || $section->kind === null || (!$all && (!$section->enabled || !$state->shows($section->shownWhen)))) {
                continue;
            }
            if ($section->isHero()) {
                $hero ??= $section;
                continue;
            }
            $view = $section->kind->view($section->data, $context, $visit);
            // A call to action is resolved last, once every other section's anchor is known; any other section that has
            // nothing to draw (an offers section with no offer visitors may see, a picture that is gone) is left out first.
            $view = $view === null || $section->kindKey === 'cta' ? $view : $this->resolve($section, $view, $context, $visit, $path, [], $presses);
            if ($view === null) {
                continue;
            }
            $key = $section->kindKey;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $drawn[] = ['section' => $section, 'view' => $view, 'anchor' => $counts[$key] === 1 ? $key : $key . '-' . $section->reference];
        }
        $anchors = [];
        foreach ($drawn as $one) {
            $anchors[$one['section']->kindKey] ??= $one['anchor'];
        }
        $shown = [];
        foreach ($drawn as $one) {
            $section = $one['section'];
            $kind = $section->kind ?? throw new \LogicException('A drawn section has its kind.');
            $resolved = $section->kindKey === 'cta' ? $this->resolve($section, $one['view'], $context, $visit, $path, $anchors, $presses) : $one['view'];
            if ($resolved === null) {
                continue;
            }
            $shown[] = $resolved + ['reference' => $section->reference, 'kind' => $section->kindKey, 'type' => $section->kindKey, 'anchor' => $one['anchor'],
                'width' => $kind->alwaysFull() ? 'full' : $section->width, 'template' => $kind->template(), 'label' => $kind->label(), 'note' => ''];
            $needs = [...$needs, ...$kind->needs()];
        }
        $note = $state->note;
        if ($note !== null) {
            foreach ($shown as $number => $section) {
                if ($section['kind'] === 'offers') {
                    $shown[$number]['note'] = $note;
                    break;
                }
            }
        }
        $heroView = $this->hero($page, $hero, $context, $visit, $card, $path, $anchors, $presses);
        if ($hero?->kind !== null) {
            $needs = [...$needs, ...$hero->kind->needs()];
        }

        return new LandingView($page, $context, $visit, $path, $heroView, $shown, HalfRows::rows($shown), $this->meta($page, $context, $visit, $heroView, $card, $path),
            array_values(array_unique($needs)), $note, $notice, $back, $presses, $all);
    }

    /**
     * What one section draws beyond its kind's view, by kind: an offers section's cards, a call to action's button, a
     * picture's address, whether a video may be framed, the countdown's moment. Null leaves the section out (an offers
     * section with no card, a picture that is gone).
     *
     * @param array<string,mixed> $view
     * @param array<string,string> $anchors
     * @param array<string,array{href:string,offer:?string,action:string,section:string}> $presses
     * @return array<string,mixed>|null
     */
    private function resolve(LandingSection $section, array $view, LandingContext $context, LandingVisit $visit, string $path, array $anchors, array &$presses): ?array
    {
        switch ($section->kindKey) {
            case 'offers':
                $cards = [];
                $buttons = array_values(array_filter(is_array($view['buttons'] ?? null) ? $view['buttons'] : [], 'is_string'));
                $promotion = ($view['show_promotion'] ?? false) === true;
                foreach (is_array($view['offers'] ?? null) ? $view['offers'] : [] as $number => $key) {
                    $offer = is_string($key) ? $this->offer($context, $key) : null;
                    if ($offer === null || !$offer->public) {
                        continue;
                    }
                    $card = $this->landing->card($offer, $visit, new CardOptions($buttons, $promotion, $context->promotion, $context->running));
                    $actions = $this->actions($card->actions, $buttons, $section->reference, $offer, $path, $visit, $presses);
                    $named = '';
                    foreach ($actions as $action) {
                        if ($action['kind'] === 'view' && $action['go'] !== '') {
                            $named = $action['go'];
                        }
                    }
                    $cards[] = ['actions' => $actions, 'name_href' => $named, 'first' => $number === 0 && ($view['first_marked'] ?? false) === true,
                        'show_promotion' => $promotion && $card->promotionPrice !== '', 'rating' => self::rating($card->rating), 'picture' => $this->cardPicture($card->picture)] + $card->toArray();
                }

                return $cards === [] ? null : $view + ['cards' => $cards];
            case 'cta':
                return $view + $this->callToAction($section, $view, $context, $visit, $path, $anchors, $presses);
            case 'picture':
                $picture = is_array($view['picture'] ?? null) ? $this->picture($view['picture'], $context, $path) : null;

                return $picture === null ? null : array_replace($view, ['picture' => $picture]);
            case 'video':
                $host = is_string($view['embed_host'] ?? null) ? $view['embed_host'] : '';

                return $view + ['framed' => $host !== '' && is_string($view['embed'] ?? null) && $this->mayFrame($host)];
            case 'countdown':
                $ends = strtotime(Rows::text($view['ends_at'] ?? ''));

                return $ends === false ? null : $view + ['ends_at_time' => $ends, 'now_time' => $context->now->getTimestamp()];
            default:
                return $view;
        }
    }

    /**
     * A call to action's button: the project's own purchase box for buying an offer of the page (else the card's buy
     * button, or what takes its place), the offer's View, a part of the page, or a page of the site.
     *
     * @param array<string,mixed> $view
     * @param array<string,string> $anchors
     * @param array<string,array{href:string,offer:?string,action:string,section:string}> $presses
     * @return array{part:?array{template:string,data:array<string,mixed>},actions:list<array<string,mixed>>,jump:?array{label:string,href:string},offer_note:string}
     */
    private function callToAction(LandingSection $section, array $view, LandingContext $context, LandingVisit $visit, string $path, array $anchors, array &$presses): array
    {
        $result = ['part' => null, 'actions' => [], 'jump' => null, 'offer_note' => ''];
        $label = Rows::text($view['label'] ?? '');
        $button = Rows::text($view['button'] ?? 'buy');
        if ($button === 'section') {
            $target = Rows::text($view['target'] ?? '');
            if (isset($anchors[$target])) {
                $result['jump'] = ['label' => $label !== '' ? $label : $this->jumpLabel($target), 'href' => '#' . $anchors[$target]];
            }

            return $result;
        }
        if ($button === 'address') {
            $target = Rows::text($view['target'] ?? '');
            if ($target !== '' && Destination::isPath($target) && Destination::problem($target) === null) {
                $result['actions'] = [$this->get(new OfferAction('view', $label !== '' ? $label : 'Find out more', 'action-open', href: $target), $section->reference, null, $path, $visit, $presses)];
            }

            return $result;
        }
        $offer = $this->offer($context, Rows::text($view['offer'] ?? ''));
        if ($offer === null || !$offer->public) {
            return $result;
        }
        if ($button === 'buy') {
            $part = $this->landing->purchase($offer, $visit, 'compact');
            if ($part !== null) {
                $result['part'] = ['template' => $part->template, 'data' => $part->data];

                return $result;
            }
        }
        $card = $this->landing->card($offer, $visit, new CardOptions([$button === 'view' ? 'view' : 'buy'], false, null, $context->running));
        $result['actions'] = $this->actions($card->actions, [$button === 'view' ? 'view' : 'buy'], $section->reference, $offer, $path, $visit, $presses);
        if ($result['actions'] === [] && $button === 'buy' && $card->note === '') {
            // A project with no way to buy from the page (GenericLanding) leads to the offer's own page, where it is had.
            $card = $this->landing->card($offer, $visit, new CardOptions(['view'], false, null, $context->running));
            $result['actions'] = $this->actions($card->actions, ['view'], $section->reference, $offer, $path, $visit, $presses);
        }
        if ($label !== '' && count($result['actions']) === 1) {
            $result['actions'][0]['label'] = $label;
        }
        if ($result['actions'] === []) {
            $result['offer_note'] = $card->note;
        }

        return $result;
    }

    /**
     * The hero as the page head: the headline (as written, else the campaign's or the offer's name), the words (else the
     * offer card's own words for the hero, OfferCard::heroWords()), the picture, the second button (to a shown part of the
     * page, or to a place the offer's card gives), the badges and rating from the offer's card. A hero hidden or removed
     * leaves the same name and badges in the head's band, with no picture.
     *
     * @param array<string,string> $anchors
     * @param array<string,array{href:string,offer:?string,action:string,section:string}> $presses
     * @return array<string,mixed>
     */
    private function hero(LandingPage $page, ?LandingSection $hero, LandingContext $context, LandingVisit $visit, ?OfferCard $card, string $path, array $anchors, array &$presses): array
    {
        $about = $page->isCampaignPage() ? ($context->campaign->name ?? '') : ($context->offer()->label ?? (string) $page->offerReference);
        $badges = $card === null ? [] : $card->badges;
        $view = $hero?->kind === null ? null : $hero->kind->view($hero->data, $context, $visit);
        if ($hero === null || $view === null) {
            return ['shown' => false, 'reference' => '', 'kicker' => '', 'headline' => $about, 'typed' => '', 'paragraphs' => [], 'picture' => null, 'secondary' => null,
                'badges' => $badges, 'rating' => null];
        }
        $typed = trim(Rows::text($view['headline'] ?? ''));
        $fallback = trim(Rows::text($view['fallback_headline'] ?? ''));
        $paragraphs = is_array($view['paragraphs'] ?? null) && $view['paragraphs'] !== [] ? $view['paragraphs'] : ($card !== null && $card->heroWords() !== '' ? LandingCopy::paragraphs($card->heroWords()) : []);
        $picture = is_array($view['picture'] ?? null) ? $this->picture($view['picture'], $context, $path) : null;
        $secondary = null;
        $target = Rows::text($view['secondary_target'] ?? 'none');
        $label = Rows::text($view['secondary_label'] ?? '');
        if ($target !== '' && $target !== 'none') {
            if (isset($anchors[$target])) {
                $secondary = ['label' => $label !== '' ? $label : $this->jumpLabel($target), 'href' => '#' . $anchors[$target], 'icon' => 'action-jump-down', 'inert' => false];
            } elseif ($card !== null && isset($card->links[$target]) && $card->links[$target]['href'] !== '') {
                $link = $this->get(new OfferAction('view', $label !== '' ? $label : $card->links[$target]['label'], 'action-preview', href: $card->links[$target]['href']), $hero->reference,
                    $context->offer(), $path, $visit, $presses, $target);
                $secondary = ['label' => $link['label'], 'href' => $link['go'], 'icon' => 'action-preview', 'inert' => $visit->preview];
            }
        }

        return ['shown' => true, 'reference' => $hero->reference, 'kicker' => Rows::text($view['kicker'] ?? ''), 'headline' => $typed !== '' ? $typed : ($fallback !== '' ? $fallback : $about),
            'typed' => $typed, 'paragraphs' => $paragraphs, 'picture' => $picture, 'secondary' => $secondary, 'badges' => $badges, 'rating' => self::rating($card?->rating)];
    }

    /**
     * The head: the page's own title, else the hero's headline; its own description, else the hero's words or the offer's
     * line, cut at 300; the canonical address (none in a preview); robots (noindex for a campaign's page, noindex and
     * nofollow for a preview); Open Graph with the share picture (the one chosen, else the hero's, else the offer's), X's
     * large card with a picture. A preview claims no address and gives no share card.
     *
     * @param array<string,mixed> $hero
     * @return array<string,mixed>
     */
    private function meta(LandingPage $page, LandingContext $context, LandingVisit $visit, array $hero, ?OfferCard $card, string $path): array
    {
        $title = trim((string) ($page->metaTitle ?? ''));
        if ($title === '') {
            $title = Rows::text($hero['headline'] ?? '');
        }
        $description = trim((string) ($page->metaDescription ?? ''));
        if ($description === '') {
            $words = [];
            foreach (is_array($hero['paragraphs'] ?? null) ? $hero['paragraphs'] : [] as $paragraph) {
                $words[] = implode(' ', is_array($paragraph) ? array_map('strval', $paragraph) : []);
            }
            $description = trim(implode(' ', $words));
            if ($description === '' && $card !== null) {
                $description = $card->line;
            }
        }
        $description = trim(mb_substr((string) preg_replace('/\s+/', ' ', $description), 0, 300));
        if ($visit->preview) {
            return ['title' => $title, 'description' => $description, 'robots' => 'noindex, nofollow'];
        }
        $meta = ['title' => $title, 'description' => $description];
        if ($page->isCampaignPage()) {
            $meta['robots'] = 'noindex';
        }
        $url = $this->absolute($path);
        $meta['canonical'] = $url;
        $og = ['type' => 'website', 'title' => $title, 'description' => $description, 'url' => $url];
        $share = null;
        if ($page->sharePicture !== null) {
            $share = $this->picture($page->sharePicture, $context, $path);
        }
        if (($share['kind'] ?? '') !== 'image' && is_array($hero['picture'] ?? null)) {
            $share = $hero['picture'];
        }
        if (($share['kind'] ?? '') !== 'image' && $card !== null) {
            $share = $this->cardPicture($card->picture);
        }
        if (($share['kind'] ?? '') === 'image' && Rows::text($share['src'] ?? '') !== '') {
            $og['image'] = $this->absolute(Rows::text($share['src']));
            $og['image:alt'] = Rows::text($share['alt'] ?? '');
        }
        $meta['og'] = $og;
        $meta['twitter_card'] = isset($og['image']) ? 'summary_large_image' : 'summary';

        return $meta;
    }

    /**
     * The actions of a card the section's buttons allow (view for View, favourite for Add to favourites, buy and what takes
     * its place for Buy now), each with its icon; a form's fields gain the page's reference and address, a link its
     * /go/{press} address.
     *
     * @param list<OfferAction> $actions
     * @param list<string> $buttons
     * @param array<string,array{href:string,offer:?string,action:string,section:string}> $presses
     * @return list<array<string,mixed>>
     */
    private function actions(array $actions, array $buttons, string $section, Offer $offer, string $path, LandingVisit $visit, array &$presses): array
    {
        $drawn = [];
        foreach ($actions as $action) {
            $button = match ($action->kind) {
                'view' => 'view',
                'favourite' => 'favourite',
                default => 'buy',
            };
            if (!in_array($button, $buttons, true)) {
                continue;
            }
            if ($action->isForm()) {
                $drawn[] = ['icon' => $action->icon !== '' ? $action->icon : self::ICONS[$action->kind], 'go' => '', 'primary' => $button === 'buy', 'inert' => $visit->preview]
                    + array_replace($action->toArray(), ['fields' => array_replace($action->fields, [LandingPresses::PAGE_FIELD => $visit->pageReference, LandingPresses::RETURN_FIELD => $path])]);
                continue;
            }
            if ($action->href === '') {
                continue;
            }
            $drawn[] = $this->get($action, $section, $offer, $path, $visit, $presses);
        }

        return $drawn;
    }

    /**
     * A GET action as the page draws it: through the page's own /go/{press} address, which records the press and leads
     * only to what was kept here.
     *
     * @param array<string,array{href:string,offer:?string,action:string,section:string}> $presses
     * @return array<string,mixed>
     */
    private function get(OfferAction $action, string $section, ?Offer $offer, string $path, LandingVisit $visit, array &$presses, string $kind = ''): array
    {
        $number = 1;
        while (isset($presses[$section . '-' . $number])) {
            $number++;
        }
        $press = $section . '-' . $number;
        $presses[$press] = ['href' => $action->href, 'offer' => $offer?->key(), 'action' => $kind !== '' ? $kind : $action->kind, 'section' => $section];

        return ['icon' => $action->icon !== '' ? $action->icon : self::ICONS[$action->kind], 'go' => rtrim($path, '/') . '/go/' . $press, 'primary' => $action->kind === 'buy',
            'inert' => $visit->preview] + $action->toArray();
    }

    /** What a button leading to a kind of section says by default: its jump label, else its label. */
    private function jumpLabel(string $kind): string
    {
        $found = $this->kinds->get($kind);
        if ($found instanceof JumpTarget) {
            return $found->jumpLabel();
        }

        return $found?->label() ?? 'Read more';
    }

    /** One of the page's offers, or any the project has, by its key; null when unknown. */
    private function offer(LandingContext $context, string $key): ?Offer
    {
        if ($key === '') {
            return null;
        }
        $offer = $context->offerByKey($key);
        if ($offer !== null) {
            return $offer;
        }
        $parts = explode(':', $key, 2);

        return count($parts) === 2 ? $this->project->offer($parts[0], $parts[1]) : null;
    }

    /**
     * A picture a section names, as the page draws it: a file at the page's own picture address, SVG markup its source
     * vouches for, or an address it is already served at; null when it is gone.
     *
     * @param array<mixed> $picture
     * @return array{kind:string,src:string,markup:string,alt:string}|null
     */
    private function picture(array $picture, LandingContext $context, string $path): ?array
    {
        $named = LandingPages::picture($picture);
        if ($named === null || $this->pictures === null) {
            return null;
        }
        $resolved = $this->pictures->resolve($named, $context);
        if ($resolved === null) {
            return null;
        }

        return match ($resolved->kind) {
            'file' => ['kind' => 'image', 'src' => rtrim($path, '/') . '/picture/' . rawurlencode($named['ref']), 'markup' => '', 'alt' => $resolved->alt],
            'svg' => (string) $resolved->markup !== '' ? ['kind' => 'svg', 'src' => '', 'markup' => (string) $resolved->markup, 'alt' => $resolved->alt] : null,
            default => (string) $resolved->address !== '' ? ['kind' => 'image', 'src' => (string) $resolved->address, 'markup' => '', 'alt' => $resolved->alt] : null,
        };
    }

    /**
     * An offer card's picture as the page draws it.
     *
     * @param array{kind:string,src?:string,markup?:string,alt?:string}|null $picture
     * @return array{kind:string,src:string,markup:string,alt:string}|null
     */
    private function cardPicture(?array $picture): ?array
    {
        if ($picture === null) {
            return null;
        }
        if ($picture['kind'] === 'svg') {
            return ($picture['markup'] ?? '') !== '' ? ['kind' => 'svg', 'src' => '', 'markup' => (string) $picture['markup'], 'alt' => (string) ($picture['alt'] ?? '')] : null;
        }

        return ($picture['src'] ?? '') !== '' ? ['kind' => 'image', 'src' => (string) $picture['src'], 'markup' => '', 'alt' => (string) ($picture['alt'] ?? '')] : null;
    }

    /**
     * Whether the site's Content-Security-Policy lets a page frame a host: its frame-src (else child-src, else default-src)
     * names the host, https: or *. Never assumed: without a policy to read, a video is a link.
     */
    private function mayFrame(string $host): bool
    {
        if ($this->policy === null || !in_array($host, VideoKind::EMBED_HOSTS, true)) {
            return false;
        }
        $directive = $this->policy->directive('frame-src') ?? $this->policy->directive('child-src') ?? $this->policy->directive('default-src') ?? '';
        foreach (preg_split('/\s+/', trim($directive)) ?: [] as $source) {
            $source = rtrim(strtolower($source), '/');
            if (in_array($source, ['*', 'https:', 'https://' . $host, $host], true)) {
                return true;
            }
            if (str_starts_with($source, 'https://*.') && str_ends_with($host, substr($source, 9))) {
                return true;
            }
        }

        return false;
    }

    /**
     * A card's rating as data.rating takes it, or null without one.
     *
     * @param array{filled:int,average:string,count:int}|null $rating
     * @return array{average:float,count:int}|null
     */
    private static function rating(?array $rating): ?array
    {
        if ($rating === null || $rating['count'] < 1) {
            return null;
        }

        return ['average' => max(0.0, min(5.0, (float) str_replace(',', '.', $rating['average']))), 'count' => $rating['count']];
    }

    /** A path on the site as a whole address, or as it is when the site's address is not known or it is already one. */
    private function absolute(string $path): string
    {
        if ($this->installation === null || !str_starts_with($path, '/') || str_starts_with($path, '//') || $this->installation->address() === '') {
            return $path;
        }

        return $this->installation->addressOf($path);
    }

    private function twig(): Environment
    {
        return $this->twig ?? throw new \LogicException('Drawing a landing page needs Twig.');
    }

    private static function nullable(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
