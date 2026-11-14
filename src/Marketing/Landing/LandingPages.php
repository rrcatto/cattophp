<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use CattoPHP\Marketing\Campaign;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Destination;
use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\Landing\Events\LandingPageChanged;
use CattoPHP\Marketing\Landing\Kinds\BaseKind;
use CattoPHP\Marketing\MarketingProject;
use CattoPHP\Marketing\MarketingRefused;
use CattoPHP\Marketing\MarketingSettings;
use CattoPHP\Marketing\NoProject;
use CattoPHP\Marketing\Offer;
use CattoPHP\Marketing\Pictures;
use CattoPHP\Marketing\Rows;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The landing pages: a campaign's page (shown at its tracking link's address, /c/{campaign}) or an offer's page (at
 * /landing/{slug}), at most one of each, made from a template as a draft. Here a page is made, found, listed, its settings
 * saved, checked before publishing, published, unpublished and deleted (only one that has never been public: a page that
 * has been public is unpublished instead); its sections are read back checked against their kinds (LandingSections changes
 * them); and an offer renamed or removed by the project is followed. Every change is in the activity log (subject
 * marketing_landing_page, by the page's reference: identifiers and field names, never copy), announced as
 * LandingPageChanged, and, on a published page, kept as a version in the same transaction.
 */
final class LandingPages
{
    public const TABLE = 'cattophp_marketing_landing_pages';
    public const SECTIONS = 'cattophp_marketing_landing_sections';

    /** The subject of a landing page's entries in the activity log, its id the page's reference. */
    public const SUBJECT = 'marketing_landing_page';
    public const SUBJECT_TEMPLATE = 'marketing_landing_template';

    public const SLUG = '/^[a-z0-9]+(-[a-z0-9]+)*$/';
    public const SLUG_LONGEST = 120;

    /** What the list may be narrowed to, and sorted by. */
    public const FILTERS = ['all', 'campaign', 'offer', 'draft', 'published'];
    public const SORTS = ['title', 'status', 'updated', 'sections', 'visits'];

    private const TITLE = "COALESCE(NULLIF(h.data->>'headline', ''), c.name, p.offer_reference)";

    public function __construct(
        private readonly Connection $connection,
        private readonly Campaigns $campaigns,
        private readonly SectionKinds $kinds,
        private readonly LandingVersions $versions,
        private readonly LandingTemplates $templates,
        private readonly MarketingProject $project = new NoProject(),
        private readonly ?PictureSources $pictureSources = null,
        private readonly ?Pictures $pictures = null,
        private readonly ?Events $events = null,
        private readonly ?ActivityLog $activity = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly MarketingSettings $settings = new MarketingSettings(),
        private readonly string $landingPrefix = 'landing',
        private readonly string $linkPrefix = 'c',
    ) {
    }

    public function get(string $reference): ?LandingPage
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE reference = :reference', ['reference' => strtolower(trim($reference))]);

        return $row === false ? null : $this->page($row);
    }

    /** A page by its reference, or a refusal a page shows as not found. */
    public function require(string $reference): LandingPage
    {
        return $this->get($reference) ?? throw MarketingRefused::because('There is no such landing page.');
    }

    public function byId(int $id): ?LandingPage
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE id = :id', ['id' => $id]);

        return $row === false ? null : $this->page($row);
    }

    /** A campaign's page, or null. */
    public function forCampaign(Campaign|int $campaign): ?LandingPage
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE campaign_id = :id', ['id' => $campaign instanceof Campaign ? $campaign->id : $campaign]);

        return $row === false ? null : $this->page($row);
    }

    /** An offer's page, or null. */
    public function forOffer(string $type, string $reference): ?LandingPage
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE offer_type = :type AND offer_reference = :reference', ['type' => $type, 'reference' => $reference]);

        return $row === false ? null : $this->page($row);
    }

    /** An offer's page by its address's slug, whatever its status, or null. */
    public function bySlug(string $slug): ?LandingPage
    {
        $slug = strtolower(trim($slug));
        if (preg_match(self::SLUG, $slug) !== 1 || strlen($slug) > self::SLUG_LONGEST) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE slug = :slug', ['slug' => $slug]);

        return $row === false ? null : $this->page($row);
    }

    /** @return list<LandingPage> every published page of an offer visitors may see now, for the sitemap */
    public function publishedOfferPages(): array
    {
        $pages = array_map($this->page(...), $this->connection->fetchAllAssociative('SELECT * FROM ' . self::TABLE . " WHERE status = 'published' AND offer_type IS NOT NULL ORDER BY slug"));

        return array_values(array_filter($pages, fn(LandingPage $page): bool => $this->project->offer((string) $page->offerType, (string) $page->offerReference)->public ?? false));
    }

    /**
     * Every shown section of a kind on a published page, with its page, for a project's own needs (such as which of its
     * media a published page plays).
     *
     * @return list<array{page:LandingPage,section:LandingSection}>
     */
    public function publishedSections(string $kind): array
    {
        $found = [];
        $rows = $this->connection->fetchAllAssociative('SELECT p.id FROM ' . self::TABLE . ' p WHERE p.status = \'published\' AND EXISTS (SELECT 1 FROM ' . self::SECTIONS
            . ' s WHERE s.landing_page_id = p.id AND s.kind = :kind AND s.enabled) ORDER BY p.id', ['kind' => $kind]);
        foreach ($rows as $row) {
            $page = $this->byId(Rows::int($row['id']));
            if ($page === null) {
                continue;
            }
            foreach ($this->sections($page) as $section) {
                if ($section->kindKey === $kind && $section->enabled && $section->isValid()) {
                    $found[] = ['page' => $page, 'section' => $section];
                }
            }
        }

        return $found;
    }

    /**
     * The pages for the list, with their titles, what they are for, sections and recent visits, narrowed by FILTERS and a
     * part of their title, campaign, address or offer, in the order asked for (SORTS).
     *
     * @return list<LandingPageSummary>
     */
    public function list(string $filter = 'all', string $search = '', string $sort = 'updated', string $direction = 'desc', int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->where($filter, $search);
        $since = $this->settings->landingRecent()->before($this->campaigns->now());
        $order = match ($sort) {
            'title' => 'lower(' . self::TITLE . ')',
            'status' => 'p.status',
            'sections' => 'section_count',
            'visits' => 'visits',
            default => 'p.updated_at',
        };
        $way = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.*, c.code AS campaign_code, c.name AS campaign_name, ' . self::TITLE . ' AS title,
                (SELECT COUNT(*) FROM ' . self::SECTIONS . ' s WHERE s.landing_page_id = p.id) AS section_count,
                (SELECT COUNT(*) FROM ' . Events::TABLE . ' e WHERE e.landing_page_id = p.id AND e.kind = :opened AND e.at >= :since) AS visits
             FROM ' . self::TABLE . ' p LEFT JOIN ' . Campaigns::TABLE . ' c ON c.id = p.campaign_id LEFT JOIN ' . self::SECTIONS . " h ON h.landing_page_id = p.id AND h.kind = 'hero'
             WHERE " . $where . ' ORDER BY ' . $order . ' ' . $way . ' NULLS LAST, p.id ' . $way . ' LIMIT :limit OFFSET :offset',
            $params + ['opened' => Events::LANDING_OPENED, 'since' => Rows::sql($since), 'limit' => max(1, $limit), 'offset' => max(0, $offset)],
        );

        return array_map(function (array $row): LandingPageSummary {
            $page = $this->page($row);
            $about = $page->isCampaignPage() ? Rows::text($row['campaign_name']) : ($this->project->offer((string) $page->offerType, (string) $page->offerReference)->label ?? (string) $page->offerReference);
            $headline = Rows::text($row['title']);
            $title = $page->isOfferPage() && $headline === $page->offerReference ? $about : $headline;

            return new LandingPageSummary($page, $title, $about, Rows::nullableText($row['campaign_code']), $this->address($page), Rows::int($row['section_count']), Rows::int($row['visits']));
        }, $rows);
    }

    /** How many pages the same narrowing finds. */
    public function count(string $filter = 'all', string $search = ''): int
    {
        [$where, $params] = $this->where($filter, $search);

        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' p LEFT JOIN ' . Campaigns::TABLE . ' c ON c.id = p.campaign_id LEFT JOIN ' . self::SECTIONS
            . " h ON h.landing_page_id = p.id AND h.kind = 'hero' WHERE " . $where, $params);
    }

    /** @return array<string,int> how many pages each filter finds */
    public function filterCounts(): array
    {
        $row = $this->connection->fetchAssociative('SELECT COUNT(*) AS "all", COUNT(*) FILTER (WHERE campaign_id IS NOT NULL) AS campaign, COUNT(*) FILTER (WHERE offer_type IS NOT NULL) AS offer,
            COUNT(*) FILTER (WHERE status = \'draft\') AS draft, COUNT(*) FILTER (WHERE status = \'published\') AS published FROM ' . self::TABLE) ?: [];

        return array_map(Rows::int(...), array_intersect_key($row, array_flip(self::FILTERS)));
    }

    /** The page's address on the site: /c/{campaign} for a campaign's, /landing/{slug} for an offer's. */
    public function address(LandingPage $page): string
    {
        if ($page->isCampaignPage()) {
            $campaign = $this->campaigns->byId((int) $page->campaignId);

            return '/' . $this->linkPrefix . '/' . ($campaign->code ?? '');
        }

        return '/' . $this->landingPrefix . '/' . $page->slug;
    }

    /** The first part of an offer page's address, such as landing. */
    public function landingPrefix(): string
    {
        return $this->landingPrefix;
    }

    /** Its title: the hero's headline, else the campaign's name or the offer's label. */
    public function title(LandingPage $page): string
    {
        $headline = $this->connection->fetchOne('SELECT data->>\'headline\' FROM ' . self::SECTIONS . " WHERE landing_page_id = :id AND kind = 'hero'", ['id' => $page->id]);
        if (is_string($headline) && trim($headline) !== '') {
            return $headline;
        }

        return $this->about($page);
    }

    /** What it is for, in words: the campaign's name, or the offer's label. */
    public function about(LandingPage $page): string
    {
        if ($page->isCampaignPage()) {
            return $this->campaigns->byId((int) $page->campaignId)->name ?? '';
        }

        return $this->project->offer((string) $page->offerType, (string) $page->offerReference)->label ?? (string) $page->offerReference;
    }

    /** The page as its kinds see it, now (or at another moment, in the installation's time zone). */
    public function context(LandingPage $page, ?DateTimeImmutable $now = null): LandingContext
    {
        $now ??= $this->campaigns->now();
        $campaign = $page->campaignId === null ? null : $this->campaigns->byId($page->campaignId);
        $kinds = array_map('strval', $this->connection->fetchFirstColumn('SELECT kind FROM ' . self::SECTIONS . ' WHERE landing_page_id = :id ORDER BY position, id', ['id' => $page->id]));
        $offer = $page->isOfferPage() ? $this->project->offer((string) $page->offerType, (string) $page->offerReference) : null;

        return $this->contextFor($campaign, $offer, $now, $page, $kinds, NotRunning::for($page, $campaign, $now)->running, $page->offerType);
    }

    /**
     * A page's context before the page is made, or for one being drawn.
     *
     * @param list<string> $kinds
     */
    public function contextFor(?Campaign $campaign, ?Offer $offer, ?DateTimeImmutable $now = null, ?LandingPage $page = null, array $kinds = [], ?bool $running = null, ?string $offerType = null): LandingContext
    {
        $now ??= $this->campaigns->now();
        $offers = [];
        if ($campaign !== null) {
            foreach ($this->campaigns->offers($campaign->id) as $chosen) {
                $known = $this->project->offer($chosen->type, $chosen->reference);
                if ($known !== null) {
                    $offers[] = $known;
                }
            }
        } elseif ($offer !== null) {
            $offers = [$offer];
        }
        $type = $offer->type ?? $offerType;
        $word = $type === null ? 'offer' : mb_strtolower($this->project->offerKinds()[$type] ?? 'offer');

        return new LandingContext($campaign !== null ? LandingContext::CAMPAIGN : LandingContext::OFFER, $campaign, $running ?? NotRunning::state($campaign, $now) === 'running',
            $offers, $now, $page, $kinds, $this->project->offersHeading(), $word, $campaign?->promotion);
    }

    /**
     * Its sections in page order, each checked again against its kind: fields added since get their defaults, copy that
     * no longer passes is marked with why (left off the page, named in the publish checks), and a kind the site no longer
     * has is kept without a kind.
     *
     * @return list<LandingSection>
     */
    public function sections(LandingPage $page, ?LandingContext $context = null): array
    {
        $context ??= $this->context($page);
        $sections = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM ' . self::SECTIONS . ' WHERE landing_page_id = :id ORDER BY position, id', ['id' => $page->id]) as $row) {
            $sections[] = $this->section($row, $context);
        }

        return $sections;
    }

    /**
     * Makes a campaign's page as a draft from a template (the first that suits when none is chosen).
     *
     * @throws MarketingRefused
     */
    public function createForCampaign(string $campaignCode, ?string $template = null, ?Actor $actor = null): TemplateResult
    {
        $campaign = $this->campaigns->require($campaignCode);
        if ($this->forCampaign($campaign) !== null) {
            throw MarketingRefused::because('This campaign already has a landing page.');
        }

        return $this->create($campaign, null, null, $template, $actor);
    }

    /**
     * Makes an offer's page as a draft from a template, its address the slug given (else the offer's own slug, else made
     * from the offer's name), with -2, -3 added when it is taken.
     *
     * @throws MarketingRefused
     */
    public function createForOffer(string $type, string $reference, ?string $template = null, ?Actor $actor = null, ?string $slug = null): TemplateResult
    {
        $offer = $this->project->offer($type, $reference) ?? throw MarketingRefused::because('There is no such offer.');
        if ($this->forOffer($offer->type, $offer->reference) !== null) {
            throw MarketingRefused::because('This ' . mb_strtolower($this->project->offerKinds()[$offer->type] ?? 'offer') . ' already has a landing page.');
        }

        return $this->create(null, $offer, $this->freeSlug($slug ?? $offer->slug ?? $offer->label), $template, $actor);
    }

    /**
     * Saves a page's settings: an offer page's address, the title and description, the share picture, and a campaign page's
     * not-running choice and where it sends visitors.
     *
     * @throws MarketingRefused every reason at once, by field
     */
    public function saveSettings(string $reference, LandingSettingsInput $input, ?Actor $actor = null): LandingPage
    {
        [$page, $changed, $details] = $this->connection->transactional(function () use ($reference, $input): array {
            $page = $this->lock($reference);
            $context = $this->context($page);
            $errors = [];
            $slug = $page->slug;
            if ($page->isOfferPage()) {
                $slug = strtolower(trim($input->slug));
                if ($slug === '') {
                    $errors['slug'] = 'The page needs an address.';
                } elseif (preg_match(self::SLUG, $slug) !== 1) {
                    $errors['slug'] = 'An address is small letters and digits joined by single hyphens, such as spring-tea.';
                } elseif (strlen($slug) > self::SLUG_LONGEST) {
                    $errors['slug'] = 'The address is longer than ' . self::SLUG_LONGEST . ' characters.';
                } elseif ($this->slugTaken($slug, $page->id)) {
                    $errors['slug'] = 'Another landing page already uses /' . $this->landingPrefix . '/' . $slug . '.';
                }
            }
            $title = $this->typed(fn(): string => LandingCopy::line($input->metaTitle, 120, 'The page title', 'meta_title'), 'meta_title', $errors);
            $description = $this->typed(fn(): string => LandingCopy::line($input->metaDescription, 300, 'The page description', 'meta_description'), 'meta_description', $errors);
            $share = null;
            try {
                $share = self::picture($input->sharePicture);
                if ($share !== null && $this->pictureSources !== null && !$this->pictureSources->exists($share, $context)) {
                    $errors['share_picture'] = 'That picture is no longer there: choose another.';
                }
            } catch (\InvalidArgumentException $refused) {
                $errors['share_picture'] = $refused->getMessage();
            }
            $notRunning = $page->notRunning;
            $notRunningTo = $page->notRunningTo;
            if ($page->isCampaignPage()) {
                $notRunning = $input->notRunning;
                $notRunningTo = trim($input->notRunningTo) === '' ? null : trim($input->notRunningTo);
                if (!array_key_exists($notRunning, NotRunning::CHOICES)) {
                    $errors['not_running'] = 'Choose what the page does while the campaign is not running.';
                } elseif ($notRunningTo !== null && (!Destination::isPath($notRunningTo) || Destination::problem($notRunningTo) !== null)) {
                    $errors['not_running_to'] = 'Type a path on the site starting with /, such as /offers.';
                } elseif ($notRunning === NotRunning::FORWARD && $notRunningTo === null) {
                    $notRunningTo = $context->campaign->destination ?? '/';
                }
                if ($notRunning !== NotRunning::FORWARD && $notRunningTo === null) {
                    $notRunningTo = $page->notRunningTo;
                }
            }
            if ($errors !== []) {
                throw new MarketingRefused($errors);
            }
            $values = ['slug' => $slug, 'meta_title' => $title === '' ? null : $title, 'meta_description' => $description === '' ? null : $description,
                'share_picture' => $share === null ? null : json_encode($share, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'not_running' => $notRunning, 'not_running_to' => $notRunningTo];
            $before = ['slug' => $page->slug, 'meta_title' => $page->metaTitle, 'meta_description' => $page->metaDescription,
                'share_picture' => $page->sharePicture === null ? null : json_encode($page->sharePicture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'not_running' => $page->notRunning, 'not_running_to' => $page->notRunningTo];
            $changed = array_keys(array_filter($values, static fn(mixed $value, string $key): bool => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));
            if ($changed === []) {
                return [$page, [], []];
            }
            $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET slug = :slug, meta_title = :meta_title, meta_description = :meta_description, share_picture = CAST(:share_picture AS jsonb),
                not_running = :not_running, not_running_to = :not_running_to, updated_at = :now WHERE id = :id', $values + ['now' => Rows::sql($this->campaigns->now()), 'id' => $page->id]);
            $details = ['changed' => $changed];
            if (in_array('slug', $changed, true)) {
                $details += ['slug_before' => (string) $page->slug, 'slug' => (string) $slug];
            }
            $this->keepVersion($page, 'The page\'s settings changed');

            return [$page, $changed, $details];
        });
        if ($changed !== []) {
            $page = $this->byId($page->id) ?? $page;
            $this->changed('marketing.landing_page.settings_saved', $page, null, 'Landing page settings saved: ' . implode(', ', $changed) . '.', $details, $actor);
        }

        return $page;
    }

    /**
     * What stops a page being published, in the editor's words; nothing when it can be. An offer's page needs its offer
     * public and a valid address; every shown section must be valid and complete (its kind's missing()) and its pictures
     * still there; at least one section must be shown. Hidden sections are never checked.
     *
     * @return list<string>
     */
    public function problems(LandingPage $page, ?LandingContext $context = null): array
    {
        $context ??= $this->context($page);
        $problems = [];
        if ($page->isOfferPage()) {
            $offer = $context->offer();
            $word = $context->offerWord;
            if ($offer === null) {
                $problems[] = 'The ' . $word . ' is no longer on the site, so its landing page cannot be published.';
            } elseif (!$offer->public) {
                $problems[] = 'The ' . $word . ' is not published, so its landing page cannot be either.';
            }
            if (preg_match(self::SLUG, (string) $page->slug) !== 1) {
                $problems[] = 'The page address is not valid.';
            }
        }
        $shown = 0;
        foreach ($this->sections($page, $context) as $section) {
            if (!$section->enabled || $section->kind === null) {
                continue;
            }
            $shown++;
            $label = $section->kind->label();
            if ($section->invalid !== '') {
                $problems[] = $label . ': ' . $section->invalid;
                continue;
            }
            if (!$section->kind->appliesTo($context)) {
                $problems[] = $label . ' does not belong on this page: remove it.';
                continue;
            }
            foreach ($section->kind->missing($section->data, $context) as $missing) {
                $problems[] = $missing;
            }
            foreach ($section->kind->pictures($section->data) as $picture) {
                if (($picture['ref'] !== '' || $picture['source'] === OfferPicture::KEY) && $this->pictureSources !== null && !$this->pictureSources->exists($picture, $context)) {
                    $problems[] = $label . ': the picture chosen is no longer there. Choose another.';
                }
            }
        }
        if ($page->sharePicture !== null && $this->pictureSources !== null && !$this->pictureSources->exists($page->sharePicture, $context)) {
            $problems[] = 'The picture used when the page is shared is no longer there. Choose another.';
        }
        if ($shown === 0) {
            $problems[] = 'Every section is hidden, so the page would be empty.';
        }

        return $problems;
    }

    /**
     * Publishes a page whose checks pass, keeping a version of it ("First published" the first time).
     *
     * @throws MarketingRefused with every problem, when any
     */
    public function publish(string $reference, ?Actor $actor = null): LandingPage
    {
        $first = $this->connection->transactional(function () use ($reference, $actor): ?bool {
            $page = $this->lock($reference);
            $problems = $this->problems($page);
            if ($problems !== []) {
                throw MarketingRefused::because('The page was not published. ' . implode(' ', $problems));
            }
            if ($page->isPublished()) {
                return null;
            }
            $now = Rows::sql($this->campaigns->now());
            $this->connection->executeStatement("UPDATE " . self::TABLE . " SET status = 'published', published_at = :now, first_published_at = COALESCE(first_published_at, :now), updated_by = :by,
                updated_at = :now WHERE id = :id", ['now' => $now, 'by' => $actor?->id, 'id' => $page->id]);
            $this->versions->write($page->id, LandingVersion::PUBLISHED, $page->hasBeenPublic() ? 'Published again' : 'First published', $this->campaigns->now(), $actor);

            return !$page->hasBeenPublic();
        });
        $page = $this->require($reference);
        if ($first !== null) {
            $this->changed('marketing.landing_page.published', $page, null, 'Landing page published at ' . $this->address($page) . '.', ['address' => $this->address($page), 'first' => $first], $actor);
        }

        return $page;
    }

    /** Takes a page down: everything on it is kept, and the moment is kept as a version. */
    public function unpublish(string $reference, ?Actor $actor = null): LandingPage
    {
        $done = $this->connection->transactional(function () use ($reference, $actor): bool {
            $page = $this->lock($reference);
            if (!$page->isPublished()) {
                return false;
            }
            $this->connection->executeStatement("UPDATE " . self::TABLE . " SET status = 'draft', published_at = NULL, updated_by = :by, updated_at = :now WHERE id = :id",
                ['by' => $actor?->id, 'now' => Rows::sql($this->campaigns->now()), 'id' => $page->id]);
            $this->versions->write($page->id, LandingVersion::UNPUBLISHED, 'Taken down', $this->campaigns->now(), $actor);

            return true;
        });
        $page = $this->require($reference);
        if ($done) {
            $this->changed('marketing.landing_page.unpublished', $page, null, 'Landing page unpublished.', ['address' => $this->address($page)], $actor);
        }

        return $page;
    }

    /**
     * Deletes a page that has never been public, with its sections; a picture only it named goes too.
     *
     * @throws MarketingRefused when it has been public: it is unpublished instead
     */
    public function delete(string $reference, ?Actor $actor = null): void
    {
        $page = $this->require($reference);
        $pictures = $this->connection->transactional(function () use ($reference): array {
            $page = $this->lock($reference);
            if ($page->hasBeenPublic()) {
                throw MarketingRefused::because('This page has been public, so it is unpublished rather than deleted.');
            }
            $pictures = $this->pagePictures($page->id);
            $this->connection->delete(self::TABLE, ['id' => $page->id]);

            return $pictures;
        });
        foreach ($pictures as $name) {
            $this->pictures?->removeUnused($name);
        }
        $this->changed('marketing.landing_page.deleted', $page, null, 'Landing page deleted.', ['address' => $this->address($page)], $actor);
    }

    /** Saves a page's sections as a template (MARKETING.CAMPAIGN.MANAGE): their copy, widths and settings, without the offers chosen. */
    public function saveAsTemplate(string $reference, string $name, string $note, ?Actor $actor = null): LandingTemplate
    {
        $page = $this->require($reference);
        $template = $this->templates->save($name, $note, $this->versions->snapshot($page->id)['sections'], $actor);
        $this->changed('marketing.landing_page.template_saved', $page, null, 'Landing page saved as the template ' . $template->name . '.', ['template' => (string) $template->reference], $actor);

        return $template;
    }

    /**
     * The pictures a page names now: those of its shown, valid sections (only those its campaign's state shows) and its
     * share picture. A page's picture address serves these and nothing else.
     *
     * @return list<array{source:string,ref:string}>
     */
    public function pictures(LandingPage $page, ?LandingContext $context = null): array
    {
        $context ??= $this->context($page);
        $now = NotRunning::for($page, $context->campaign, $context->now);
        $pictures = $page->sharePicture === null ? [] : [$page->sharePicture];
        foreach ($this->sections($page, $context) as $section) {
            if ($section->enabled && $section->isValid() && $now->shows($section->shownWhen) && $section->kind !== null) {
                $pictures = [...$pictures, ...$section->kind->pictures($section->data)];
            }
        }

        return array_values(array_unique($pictures, SORT_REGULAR));
    }

    /** Whether a page names a picture now (pictures()), so its picture address may serve it. */
    public function namesPicture(LandingPage $page, string $source, string $ref, ?LandingContext $context = null): bool
    {
        return in_array(['source' => $source, 'ref' => $ref], $this->pictures($page, $context), true);
    }

    /**
     * Whether a page holds an offer: an offer page its own offer; a campaign's page one of its campaign's, or one a shown
     * section names.
     */
    public function holdsOffer(LandingPage $page, string $key): bool
    {
        if ($page->isOfferPage()) {
            return $page->offerKey() === $key;
        }
        $context = $this->context($page);
        if ($context->offerByKey($key) !== null) {
            return true;
        }
        foreach ($this->sections($page, $context) as $section) {
            if ($section->enabled && (in_array($key, is_array($section->data['offers'] ?? null) ? $section->data['offers'] : [], true) || ($section->data['offer'] ?? null) === $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An offer's code changed in the project: its page, the sections naming it, the campaigns promoting it and its events
     * follow. Kept versions stay as they were. Gives how many pages and sections changed.
     */
    public function offerRenamed(string $type, string $from, string $to): int
    {
        if ($from === $to) {
            return 0;
        }
        $old = json_encode($type . ':' . $from, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $new = json_encode($type . ':' . $to, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->connection->transactional(function () use ($type, $from, $to, $old, $new): int {
            $changed = (int) $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET offer_reference = :to WHERE offer_type = :type AND offer_reference = :from',
                ['type' => $type, 'from' => $from, 'to' => $to]);
            $changed += (int) $this->connection->executeStatement('UPDATE ' . self::SECTIONS . ' SET data = CAST(replace(data::text, :old, :new) AS jsonb) WHERE strpos(data::text, :old) > 0',
                ['old' => $old, 'new' => $new]);
            $this->campaigns->offerRenamed($type, $from, $to);
            $this->events?->offerRenamed($type, $from, $to);

            return $changed;
        });
    }

    /**
     * An offer was removed from the project: its own page goes (with its sections and versions), and every section that
     * names it no longer does. Gives how many pages and sections changed.
     */
    public function offerRemoved(string $type, string $reference): int
    {
        $key = $type . ':' . $reference;
        $quoted = json_encode($key, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pictures = [];
        $changed = $this->connection->transactional(function () use ($type, $reference, $key, $quoted, &$pictures): int {
            $changed = 0;
            $page = $this->forOffer($type, $reference);
            if ($page !== null) {
                $pictures = $this->pagePictures($page->id);
                $this->connection->delete(self::TABLE, ['id' => $page->id]);
                $changed++;
            }
            foreach ($this->connection->fetchAllAssociative('SELECT id, data FROM ' . self::SECTIONS . ' WHERE strpos(data::text, :key) > 0', ['key' => $quoted]) as $row) {
                $data = json_decode(Rows::text($row['data']), true);
                if (!is_array($data)) {
                    continue;
                }
                if (is_array($data['offers'] ?? null)) {
                    $data['offers'] = array_values(array_filter($data['offers'], static fn(mixed $offer): bool => $offer !== $key));
                }
                if (($data['offer'] ?? null) === $key) {
                    $data['offer'] = '';
                }
                if (is_array($data['picture'] ?? null) && ($data['picture']['source'] ?? null) === OfferPicture::KEY && ($data['picture']['ref'] ?? null) === $key) {
                    $data['picture']['ref'] = '';
                }
                $this->connection->executeStatement('UPDATE ' . self::SECTIONS . ' SET data = CAST(:data AS jsonb) WHERE id = :id',
                    ['data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id' => $row['id']]);
                $changed++;
            }

            return $changed;
        });
        foreach ($pictures as $name) {
            $this->pictures?->removeUnused($name);
        }

        return $changed;
    }

    /**
     * The page, locked until the transaction ends, so changes to it queue behind each other. For LandingSections and this
     * class alone.
     *
     * @internal
     */
    public function lock(string $reference): LandingPage
    {
        $id = $this->connection->fetchOne('SELECT id FROM ' . self::TABLE . ' WHERE reference = :reference FOR UPDATE', ['reference' => strtolower(trim($reference))]);
        if ($id === false) {
            throw MarketingRefused::because('There is no such landing page.');
        }

        return $this->byId((int) $id) ?? throw MarketingRefused::because('There is no such landing page.');
    }

    /**
     * After a change to a page, inside its transaction: a published page keeps a version of what visitors now see, and its
     * last change is noted.
     *
     * @internal
     */
    public function keepVersion(LandingPage $page, string $whatChanged, ?Actor $actor = null): void
    {
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET updated_by = :by, updated_at = :now WHERE id = :id', ['by' => $actor?->id, 'now' => Rows::sql($this->campaigns->now()), 'id' => $page->id]);
        if ($page->isPublished()) {
            $this->versions->write($page->id, LandingVersion::PUBLISHED, $whatChanged, $this->campaigns->now(), $actor);
        }
    }

    /**
     * After a change, once it is saved: its activity entry (subject marketing_landing_page) and its announcement.
     *
     * @param array<string,mixed> $details identifiers and field names, never copy
     * @internal
     */
    public function changed(string $action, LandingPage $page, ?string $sectionKind, string $summary, array $details, ?Actor $actor): void
    {
        $about = $page->isCampaignPage() ? ['campaign' => $this->campaigns->byId((int) $page->campaignId)->code ?? null] : ['offer' => $page->offerKey()];
        $this->activity?->record($action, ['page' => $page->reference] + $about + ($sectionKind === null ? [] : ['kind' => $sectionKind]) + $details, self::SUBJECT, $page->reference, $actor);
        $this->dispatcher?->dispatch(new LandingPageChanged($action, $page, $sectionKind, $summary, $details, $actor));
    }

    /**
     * Writes a page's sections in order, the hero at 0 and the others 1 to n, each with its reference (a new one unless a
     * free one is given). Inside the page's transaction.
     *
     * @param list<array{kind:string,width:string,shown_when:string,enabled:bool,data:array<string,mixed>,reference?:string,unsaved?:bool}> $sections
     * @internal
     */
    public function insertSections(int $pageId, array $sections, int $start = 1): void
    {
        $now = Rows::sql($this->campaigns->now());
        $position = $start;
        foreach ($sections as $section) {
            $reference = $section['reference'] ?? null;
            if ($reference === null || $this->connection->fetchOne('SELECT 1 FROM ' . self::SECTIONS . ' WHERE reference = :reference', ['reference' => $reference]) !== false) {
                $reference = $this->freeReference();
            }
            $this->connection->executeStatement('INSERT INTO ' . self::SECTIONS . ' (reference, landing_page_id, kind, position, enabled, unsaved, width, shown_when, data, created_at, updated_at)
                VALUES (:reference, :page, :kind, :position, :enabled, :unsaved, :width, :shown_when, CAST(:data AS jsonb), :now, :now)', ['reference' => $reference, 'page' => $pageId,
                'kind' => $section['kind'], 'position' => $section['kind'] === 'hero' ? 0 : $position++, 'enabled' => $section['enabled'] ? 'true' : 'false',
                'unsaved' => ($section['unsaved'] ?? false) ? 'true' : 'false', 'width' => $section['kind'] === 'hero' ? 'full' : $section['width'], 'shown_when' => $section['shown_when'],
                'data' => json_encode($section['data'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'now' => $now]);
        }
    }

    /** A reference no section, page or template has. */
    public function freeReference(): string
    {
        do {
            $reference = Rows::reference();
        } while ($this->connection->fetchOne('SELECT 1 FROM ' . self::SECTIONS . ' WHERE reference = :r UNION ALL SELECT 1 FROM ' . self::TABLE . ' WHERE reference = :r', ['r' => $reference]) !== false);

        return $reference;
    }

    /**
     * The uploaded pictures a section's data names (their stored names), so one no longer named can be removed.
     *
     * @param array<array-key,mixed> $data
     * @return list<string>
     */
    public static function uploadedPictures(array $data): array
    {
        $names = [];
        array_walk_recursive($data, static function (mixed $value) use (&$names): void {
            if (is_string($value) && preg_match(Pictures::NAME, $value) === 1) {
                $names[] = $value;
            }
        });

        return array_values(array_unique($names));
    }

    /**
     * A share picture as posted or stored: {source, ref}, or null for none.
     *
     * @param array<string,mixed>|null $value
     * @return array{source:string,ref:string}|null
     */
    public static function picture(?array $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $source = is_string($value['source'] ?? null) ? trim($value['source']) : '';
        if ($source === '' || $source === 'none') {
            return null;
        }
        $ref = is_scalar($value['ref'] ?? null) ? trim((string) $value['ref']) : '';
        if (preg_match(BaseKind::SOURCE, $source) !== 1 || ($ref !== '' && preg_match(BaseKind::REF, $ref) !== 1)) {
            throw new \InvalidArgumentException('Choose the picture from the choices.');
        }

        return ['source' => $source, 'ref' => $ref];
    }

    /** Copies a campaign's page to its copy, as a draft with no versions: for Campaigns::copy(), inside its transaction. */
    public static function copyForCampaign(Connection $connection, int $from, int $to, ?string $by, string $now): void
    {
        $page = $connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE campaign_id = :id', ['id' => $from]);
        if ($page === false) {
            return;
        }
        $id = (int) $connection->fetchOne('INSERT INTO ' . self::TABLE . ' (reference, campaign_id, meta_title, meta_description, share_picture, not_running, not_running_to, created_by, updated_by, created_at, updated_at)
            SELECT :reference, :to, meta_title, meta_description, share_picture, not_running, not_running_to, :by, :by, :now, :now FROM ' . self::TABLE . ' WHERE id = :id RETURNING id',
            ['reference' => self::unusedReference($connection), 'to' => $to, 'by' => $by, 'now' => $now, 'id' => $page['id']]);
        foreach ($connection->fetchFirstColumn('SELECT id FROM ' . self::SECTIONS . ' WHERE landing_page_id = :id ORDER BY position, id', ['id' => $page['id']]) as $section) {
            $connection->executeStatement('INSERT INTO ' . self::SECTIONS . ' (reference, landing_page_id, kind, position, enabled, unsaved, width, shown_when, data, created_at, updated_at)
                SELECT :reference, :page, kind, position, enabled, unsaved, width, shown_when, data, :now, :now FROM ' . self::SECTIONS . ' WHERE id = :id',
                ['reference' => self::unusedReference($connection), 'page' => $id, 'now' => $now, 'id' => $section]);
        }
    }

    /** Whether a campaign's page has ever been public, so the campaign is archived rather than deleted. */
    public static function campaignPageWasPublic(Connection $connection, int $campaignId): bool
    {
        return $connection->fetchOne('SELECT 1 FROM ' . self::TABLE . ' WHERE campaign_id = :id AND first_published_at IS NOT NULL', ['id' => $campaignId]) !== false;
    }

    /**
     * Deletes a campaign's page that was never public, for Campaigns::delete(), and gives the pictures its sections named.
     *
     * @return list<string>
     */
    public static function deleteForCampaign(Connection $connection, int $campaignId): array
    {
        $pictures = [];
        foreach ($connection->fetchFirstColumn('SELECT s.data::text FROM ' . self::SECTIONS . ' s JOIN ' . self::TABLE . ' p ON p.id = s.landing_page_id WHERE p.campaign_id = :id', ['id' => $campaignId]) as $data) {
            $decoded = json_decode((string) $data, true);
            $pictures = [...$pictures, ...self::uploadedPictures(is_array($decoded) ? $decoded : [])];
        }
        $connection->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE campaign_id = :id AND first_published_at IS NULL', ['id' => $campaignId]);

        return array_values(array_unique($pictures));
    }

    private static function unusedReference(Connection $connection): string
    {
        do {
            $reference = Rows::reference();
        } while ($connection->fetchOne('SELECT 1 FROM ' . self::SECTIONS . ' WHERE reference = :r UNION ALL SELECT 1 FROM ' . self::TABLE . ' WHERE reference = :r', ['r' => $reference]) !== false);

        return $reference;
    }

    /** @return list<string> the uploaded pictures a page's sections and share picture name */
    private function pagePictures(int $pageId): array
    {
        $names = [];
        foreach ($this->connection->fetchFirstColumn('SELECT data::text FROM ' . self::SECTIONS . ' WHERE landing_page_id = :id UNION ALL SELECT COALESCE(share_picture::text, \'{}\') FROM ' . self::TABLE . ' WHERE id = :id',
            ['id' => $pageId]) as $data) {
            $decoded = json_decode((string) $data, true);
            $names = [...$names, ...self::uploadedPictures(is_array($decoded) ? $decoded : [])];
        }

        return array_values(array_unique($names));
    }

    private function create(?Campaign $campaign, ?Offer $offer, ?string $slug, ?string $templateKey, ?Actor $actor): TemplateResult
    {
        $context = $this->contextFor($campaign, $offer);
        if ($templateKey !== null && $templateKey !== '') {
            $template = $this->templates->get($templateKey);
            if ($template === null || !$template->suitsPage($context)) {
                throw new MarketingRefused(['template' => 'Choose one of the templates.']);
            }
        } else {
            $template = $this->templates->available($context, false)[0] ?? null;
        }
        $applied = $template === null ? new TemplateApplied([['kind' => 'hero', 'width' => 'full', 'shown_when' => 'always', 'enabled' => true,
            'data' => ($this->kinds->get('hero') ?? throw new \LogicException('The hero kind is missing.'))->defaults()]]) : $this->templates->apply($template, $context);
        $reference = $this->connection->transactional(function () use ($campaign, $offer, $slug, $applied, $actor): string {
            $reference = $this->freeReference();
            $now = Rows::sql($this->campaigns->now());
            $id = (int) $this->connection->fetchOne('INSERT INTO ' . self::TABLE . ' (reference, campaign_id, offer_type, offer_reference, slug, created_by, updated_by, created_at, updated_at)
                VALUES (:reference, :campaign, :type, :offer, :slug, :by, :by, :now, :now) RETURNING id', ['reference' => $reference, 'campaign' => $campaign?->id, 'type' => $offer?->type,
                'offer' => $offer?->reference, 'slug' => $slug, 'by' => $actor?->id, 'now' => $now]);
            $this->insertSections($id, $applied->sections);

            return $reference;
        });
        $page = $this->require($reference);
        $this->changed('marketing.landing_page.created', $page, null, 'Landing page made at ' . $this->address($page) . '.',
            ['address' => $this->address($page), 'template' => $template?->key, 'skipped' => $applied->skipped], $actor);

        return new TemplateResult($page, $applied->skipped);
    }

    private function slugTaken(string $slug, ?int $except = null): bool
    {
        return $this->connection->fetchOne('SELECT 1 FROM ' . self::TABLE . ' WHERE slug = :slug AND id IS DISTINCT FROM :except', ['slug' => $slug, 'except' => $except]) !== false;
    }

    /** A free address made from a slug or a name: -2, -3 added when it is taken. */
    private function freeSlug(string $base): string
    {
        $base = Rows::slug($base, self::SLUG_LONGEST - 10);
        if ($base === '') {
            $base = 'page';
        }
        $slug = $base;
        for ($n = 2; $this->slugTaken($slug); $n++) {
            $slug = rtrim($base, '-') . '-' . $n;
        }

        return $slug;
    }

    /**
     * @param array<string,string> $errors
     * @param callable():string $rule
     */
    private function typed(callable $rule, string $field, array &$errors): string
    {
        try {
            return $rule();
        } catch (\InvalidArgumentException $refused) {
            $errors[$field] = $refused->getMessage();

            return '';
        }
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function where(string $filter, string $search): array
    {
        $conditions = [match ($filter) {
            'campaign' => 'p.campaign_id IS NOT NULL',
            'offer' => 'p.offer_type IS NOT NULL',
            'draft' => "p.status = 'draft'",
            'published' => "p.status = 'published'",
            default => 'TRUE',
        }];
        $params = [];
        $search = trim($search);
        if ($search !== '') {
            $conditions[] = "(c.name ILIKE :like OR c.code ILIKE :like OR p.slug ILIKE :like OR p.offer_reference ILIKE :like OR h.data->>'headline' ILIKE :like OR p.meta_title ILIKE :like)";
            $params['like'] = Rows::like($search);
        }

        return [implode(' AND ', $conditions), $params];
    }

    /** @param array<string,mixed> $row */
    private function section(array $row, LandingContext $context): LandingSection
    {
        $zone = $this->campaigns->zone();
        $key = Rows::text($row['kind']);
        $kind = $this->kinds->get($key);
        $stored = json_decode(Rows::text($row['data']), true);
        $stored = is_array($stored) ? $stored : [];
        $data = $stored;
        $invalid = '';
        if ($kind !== null) {
            try {
                $data = $kind->normalise($stored + $kind->defaults(), $context);
            } catch (\InvalidArgumentException $problem) {
                $data = $kind->defaults();
                $invalid = $problem->getMessage();
            }
        }

        return new LandingSection(Rows::int($row['id']), Rows::text($row['reference']), Rows::int($row['landing_page_id']), $key, Rows::int($row['position']), Rows::bool($row['enabled']),
            Rows::text($row['width']), Rows::text($row['shown_when']), $data, $kind, $invalid, Rows::bool($row['unsaved']), Rows::instant($row['created_at'], $zone) ?? $this->campaigns->now(),
            Rows::instant($row['updated_at'], $zone) ?? $this->campaigns->now());
    }

    /** @param array<string,mixed> $row */
    private function page(array $row): LandingPage
    {
        $zone = $this->campaigns->zone();
        $share = json_decode(Rows::text($row['share_picture'] ?? ''), true);
        $share = is_array($share) && is_string($share['source'] ?? null) ? ['source' => $share['source'], 'ref' => is_string($share['ref'] ?? null) ? $share['ref'] : ''] : null;

        return new LandingPage(Rows::int($row['id']), Rows::text($row['reference']), Rows::nullableInt($row['campaign_id']), Rows::nullableText($row['offer_type']),
            Rows::nullableText($row['offer_reference']), Rows::nullableText($row['slug']), Rows::text($row['status']), Rows::instant($row['first_published_at'], $zone),
            Rows::instant($row['published_at'], $zone), Rows::nullableText($row['meta_title']), Rows::nullableText($row['meta_description']), $share, Rows::text($row['not_running']),
            Rows::nullableText($row['not_running_to']), Rows::nullableText($row['created_by']), Rows::nullableText($row['updated_by']), Rows::instant($row['created_at'], $zone) ?? $this->campaigns->now(),
            Rows::instant($row['updated_at'], $zone) ?? $this->campaigns->now());
    }
}
