<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use CattoPHP\Activity\ActivityEntry;
use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use CattoPHP\Installation\Installation;
use CattoPHP\Marketing\Landing\LandingPages;
use CattoPHP\Marketing\Sites\Site;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * The campaigns: the list with its status filter, search and sort; making, changing, copying, activating, pausing,
 * archiving, restoring and deleting one; the offers each promotes; and its history from the activity log. What a person
 * types is checked here and refused in plain words by its field (MarketingRefused). A campaign is deleted only while it
 * has no publication, nobody has opened its links and its landing page was never public; otherwise it is archived. Its
 * code is changeable only while nobody has opened its links, since they are out in the world. A copy takes its landing
 * page as a draft.
 */
final class Campaigns
{
    public const TABLE = 'cattophp_marketing_campaigns';
    public const OFFERS = 'cattophp_marketing_campaign_offers';
    public const CODE = '/^[a-z0-9][a-z0-9-]{1,39}$/';

    /** Codes no campaign may have, since its page's address would be another page's (/marketing/campaigns/new). */
    public const RESERVED = ['new'];

    /** The subject of a campaign's entries in the activity log, its id being the campaign's code at the time. */
    public const SUBJECT = 'marketing_campaign';

    /** The worked-out statuses a list may be narrowed to, and current: every campaign that is not archived. */
    public const FILTERS = ['current', 'draft', 'scheduled', 'active', 'paused', 'ended', 'archived'];

    /** What a list may be sorted by. */
    public const SORTS = ['name', 'code', 'status', 'starts', 'ends', 'created', 'updated', 'publications', 'clicks', 'unique_visitors', 'spend', 'budget'];

    /** Each campaign's figures over its whole life, for the list. */
    private const SUMMARY = '(SELECT COUNT(*) FROM ' . Publications::TABLE . ' p WHERE p.campaign_id = c.id) AS publication_count,
        (SELECT COUNT(*) FROM ' . Touches::TABLE . ' t WHERE t.campaign_id = c.id AND t.counted) AS clicks,
        (SELECT COUNT(DISTINCT t.visitor_id) + COUNT(*) FILTER (WHERE t.visitor_id IS NULL) FROM ' . Touches::TABLE . ' t WHERE t.campaign_id = c.id AND t.counted) AS unique_visitors,
        (SELECT COALESCE(SUM(p.cost_minor), 0) FROM ' . Publications::TABLE . ' p WHERE p.campaign_id = c.id)
            + (SELECT COALESCE(SUM(s.amount_minor), 0) FROM ' . Spend::TABLE . ' s WHERE s.campaign_id = c.id) AS spend';

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly Connection $connection,
        private readonly MarketingProject $project = new NoProject(),
        private readonly ?ActivityLog $activity = null,
        ?ClockInterface $clock = null,
        private readonly ?Installation $installation = null,
        private readonly ?Pictures $pictures = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * Campaigns with their figures, narrowed to a status (FILTERS; null for every one) and a part of their name, code or
     * description, in the order asked for (SORTS), newest change first by default.
     *
     * @return list<CampaignSummary>
     */
    public function list(?string $status = null, string $search = '', string $sort = 'updated', string $direction = 'desc', int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->where($status, $search);
        $order = match ($sort) {
            'name' => 'lower(c.name)',
            'code' => 'c.code',
            'status' => 'c.status',
            'starts' => 'c.starts_at',
            'ends' => 'c.ends_at',
            'created' => 'c.created_at',
            'publications' => 'publication_count',
            'clicks' => 'clicks',
            'unique_visitors' => 'unique_visitors',
            'spend' => 'spend',
            'budget' => 'c.budget_minor',
            default => 'c.updated_at',
        };
        $way = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.*, ' . self::SUMMARY . ' FROM ' . self::TABLE . ' c WHERE ' . $where . ' ORDER BY ' . $order . ' ' . $way . ' NULLS LAST, c.id ' . $way . ' LIMIT :limit OFFSET :offset',
            $params + ['limit' => max(1, $limit), 'offset' => max(0, $offset)],
        );

        return array_map(fn(array $row): CampaignSummary => new CampaignSummary($this->campaign($row), Rows::int($row['publication_count']), Rows::int($row['clicks']),
            Rows::int($row['unique_visitors']), Rows::int($row['spend'])), $rows);
    }

    /** How many campaigns the same narrowing finds. */
    public function count(?string $status = null, string $search = ''): int
    {
        [$where, $params] = $this->where($status, $search);

        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' c WHERE ' . $where, $params);
    }

    /** @return array<string,int> how many campaigns there are of each worked-out status, with all and current */
    public function statusCounts(): array
    {
        $counts = ['all' => 0, 'current' => 0] + array_fill_keys(array_keys(Campaign::SHOWN), 0);
        $now = $this->now();
        foreach ($this->connection->fetchAllAssociative('SELECT status, starts_at, ends_at FROM ' . self::TABLE) as $row) {
            $shown = $this->shownStatus(Rows::text($row['status']), Rows::instant($row['starts_at'], $this->zone()), Rows::instant($row['ends_at'], $this->zone()), $now);
            $counts[$shown]++;
            $counts['all']++;
            if ($shown !== 'archived') {
                $counts['current']++;
            }
        }

        return $counts;
    }

    public function get(string $code): ?Campaign
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE code = :code', ['code' => strtolower(trim($code))]);

        return $row === false ? null : $this->campaign($row);
    }

    public function byId(int $id): ?Campaign
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE id = :id', ['id' => $id]);

        return $row === false ? null : $this->campaign($row);
    }

    /** A campaign by its code, or a refusal a page shows as not found. */
    public function require(string $code): Campaign
    {
        return $this->get($code) ?? throw MarketingRefused::because('There is no such campaign.');
    }

    /** @return list<CampaignOffer> the offers a campaign promotes, in their order */
    public function offers(int $campaignId): array
    {
        return array_map(static fn(array $row): CampaignOffer => new CampaignOffer(Rows::text($row['offer_type']), Rows::text($row['offer_reference']), Rows::text($row['label']), Rows::int($row['position'])),
            $this->connection->fetchAllAssociative('SELECT * FROM ' . self::OFFERS . ' WHERE campaign_id = :id ORDER BY position, label', ['id' => $campaignId]));
    }

    /**
     * A new campaign, a draft. Its code is suggested from its name when none is typed.
     *
     * @throws MarketingRefused
     */
    public function create(CampaignInput $input, ?Actor $actor = null): Campaign
    {
        $values = $this->checked($input, null);
        $id = $this->connection->transactional(function (Connection $connection) use ($values, $actor): int {
            $id = (int) $connection->fetchOne(
                'INSERT INTO ' . self::TABLE . ' (code, name, description, objective, starts_at, ends_at, budget_minor, destination, promotion, created_by, created_at, updated_at)
                 VALUES (:code, :name, :description, :objective, :starts_at, :ends_at, :budget_minor, :destination, :promotion, :created_by, :now, :now) RETURNING id',
                $values['row'] + ['created_by' => $actor?->id, 'now' => Rows::sql($this->now())],
            );
            $this->saveOffers($id, $values['offers']);

            return $id;
        });
        $campaign = $this->byId($id) ?? throw MarketingRefused::because('The campaign could not be read back.');
        $this->log('marketing.campaign_created', $campaign, ['name' => $campaign->name], $actor);

        return $campaign;
    }

    /**
     * The campaign a visitor arrived for from a connected site, by the code that site gave: the one with that code, else
     * a new one made for that site: active, with no dates, the name given, its links leading to the home page, and a
     * description saying how it was made. Two arrivals at once make it once.
     *
     * @throws MarketingRefused when the code is not one a campaign may have
     */
    public function makeForArrival(string $code, string $name, Site $site): Campaign
    {
        $code = strtolower(trim($code));
        if (preg_match(self::CODE, $code) !== 1 || in_array($code, self::RESERVED, true)) {
            throw MarketingRefused::because(sprintf('A campaign\'s code is small letters, digits and hyphens, 2 to 40: "%s" from %s is not.', mb_substr($code, 0, 40), $site->name));
        }
        $found = $this->get($code);
        if ($found !== null) {
            return $found;
        }
        $name = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $name)), 0, 120);
        $now = Rows::sql($this->now());
        $id = $this->connection->fetchOne(
            "INSERT INTO " . self::TABLE . " (code, name, description, status, destination, origin_site, created_at, updated_at)
             VALUES (:code, :name, :description, 'active', '/', :site, :now, :now) ON CONFLICT (code) DO NOTHING RETURNING id",
            ['code' => $code, 'name' => $name === '' ? $code : $name, 'description' => 'Made when a visitor arrived by a link from ' . $site->name . '.', 'site' => $site->key, 'now' => $now],
        );
        $campaign = ($id === false ? $this->get($code) : $this->byId((int) $id)) ?? throw MarketingRefused::because('The campaign could not be read back.');
        if ($id !== false) {
            $this->log('marketing.campaign_created', $campaign, ['name' => $campaign->name, 'site' => $site->key], null);
        }

        return $campaign;
    }

    /**
     * A campaign changed as typed on its edit page. Its code changes only while nobody has opened its links.
     *
     * @throws MarketingRefused
     */
    public function change(string $code, CampaignInput $input, ?Actor $actor = null): Campaign
    {
        $campaign = $this->require($code);
        $values = $this->checked($input, $campaign);
        $this->connection->transactional(function (Connection $connection) use ($campaign, $values): void {
            $row = $values['row'];
            $former = $campaign->formerCodes;
            if ($row['code'] !== $campaign->code) {
                $former = array_values(array_diff([...$former, $campaign->code], [$row['code']]));
            }
            $connection->executeStatement(
                'UPDATE ' . self::TABLE . ' SET code = :code, former_codes = CAST(:former AS jsonb), name = :name, description = :description, objective = :objective, starts_at = :starts_at,
                    ends_at = :ends_at, budget_minor = :budget_minor, destination = :destination, promotion = :promotion, updated_at = :now WHERE id = :id',
                $row + ['former' => json_encode($former, JSON_THROW_ON_ERROR), 'now' => Rows::sql($this->now()), 'id' => $campaign->id],
            );
            $this->saveOffers($campaign->id, $values['offers']);
        });
        $changed = $this->byId($campaign->id) ?? $campaign;
        $details = ['name' => $changed->name];
        if ($changed->code !== $campaign->code) {
            $details['code_before'] = $campaign->code;
        }
        $this->log('marketing.campaign_changed', $changed, $details, $actor);

        return $changed;
    }

    /** Makes a draft or paused campaign active: its links count from its start. */
    public function activate(string $code, ?Actor $actor = null): Campaign
    {
        $campaign = $this->require($code);
        if (!$campaign->canActivate()) {
            throw MarketingRefused::because($campaign->status === 'active' ? 'This campaign is already active.' : 'Restore this campaign before activating it.');
        }

        return $this->setStatus($campaign, 'active', 'marketing.campaign_activated', $actor);
    }

    /** Pauses an active campaign: its links still lead where they did, and their clicks are set aside. */
    public function pause(string $code, ?Actor $actor = null): Campaign
    {
        $campaign = $this->require($code);
        if (!$campaign->canPause()) {
            throw MarketingRefused::because('Only an active campaign can be paused.');
        }

        return $this->setStatus($campaign, 'paused', 'marketing.campaign_paused', $actor);
    }

    /** Archives a campaign, keeping everything: its links still lead where they did. */
    public function archive(string $code, ?Actor $actor = null): Campaign
    {
        $campaign = $this->require($code);
        if (!$campaign->canArchive()) {
            throw MarketingRefused::because('This campaign is already archived.');
        }
        $this->connection->update(self::TABLE, ['status_before_archive' => $campaign->status], ['id' => $campaign->id]);

        return $this->setStatus($campaign, 'archived', 'marketing.campaign_archived', $actor);
    }

    /** Brings an archived campaign back with the status it had before (an active one comes back paused, to be looked at). */
    public function restore(string $code, ?Actor $actor = null): Campaign
    {
        $campaign = $this->require($code);
        if (!$campaign->canRestore()) {
            throw MarketingRefused::because('Only an archived campaign can be restored.');
        }
        $status = $campaign->statusBeforeArchive === 'draft' ? 'draft' : 'paused';
        $this->connection->update(self::TABLE, ['status_before_archive' => null], ['id' => $campaign->id]);

        return $this->setStatus($campaign, $status, 'marketing.campaign_restored', $actor);
    }

    /**
     * A copy of a campaign as a new draft: its words, budget, destination, promotion, offers and adverts (not retired),
     * with a new code and no dates, publications, spend or clicks.
     */
    public function copy(string $code, ?Actor $actor = null): Campaign
    {
        $campaign = $this->require($code);
        $newCode = $this->suggestCode($campaign->code . '-copy');
        $name = mb_substr($campaign->name . ' (copy)', 0, 120);
        $id = $this->connection->transactional(function (Connection $connection) use ($campaign, $newCode, $name, $actor): int {
            $now = Rows::sql($this->now());
            $id = (int) $connection->fetchOne(
                'INSERT INTO ' . self::TABLE . ' (code, name, description, objective, budget_minor, destination, promotion, created_by, created_at, updated_at)
                 SELECT :code, :name, description, objective, budget_minor, destination, promotion, :created_by, :now, :now FROM ' . self::TABLE . ' WHERE id = :id RETURNING id',
                ['code' => $newCode, 'name' => $name, 'created_by' => $actor?->id, 'now' => $now, 'id' => $campaign->id],
            );
            $connection->executeStatement('INSERT INTO ' . self::OFFERS . ' (campaign_id, offer_type, offer_reference, label, position) SELECT :new, offer_type, offer_reference, label, position FROM ' . self::OFFERS . ' WHERE campaign_id = :id',
                ['new' => $id, 'id' => $campaign->id]);
            // Its landing page comes too, as a draft without its versions.
            LandingPages::copyForCampaign($connection, $campaign->id, $id, $actor?->id, (string) $now);
            foreach ($connection->fetchFirstColumn('SELECT id FROM ' . Adverts::TABLE . ' WHERE campaign_id = :id AND retired_at IS NULL ORDER BY id', ['id' => $campaign->id]) as $advert) {
                $connection->executeStatement(
                    'INSERT INTO ' . Adverts::TABLE . ' (reference, campaign_id, name, kind, headline, words, media_address, picture, notes, created_by, created_at, updated_at)
                     SELECT :reference, :new, name, kind, headline, words, media_address, picture, notes, :created_by, :now, :now FROM ' . Adverts::TABLE . ' WHERE id = :advert',
                    ['reference' => Rows::reference(), 'new' => $id, 'created_by' => $actor?->id, 'now' => $now, 'advert' => $advert],
                );
            }

            return $id;
        });
        $copy = $this->byId($id) ?? throw MarketingRefused::because('The copy could not be read back.');
        $this->log('marketing.campaign_copied', $copy, ['name' => $copy->name, 'copied_from' => $campaign->code], $actor);

        return $copy;
    }

    /**
     * Deletes a campaign that has no publication and whose links nobody has opened, with its offers, adverts and spend.
     *
     * @throws MarketingRefused otherwise: it is archived instead
     */
    public function delete(string $code, ?Actor $actor = null): void
    {
        $campaign = $this->require($code);
        if (!$this->canDelete($campaign)) {
            throw MarketingRefused::because('This campaign has been published, its links have been opened or its landing page has been public, so it is kept: archive it instead.');
        }
        $pictures = array_map('strval', $this->connection->fetchFirstColumn('SELECT picture FROM ' . Adverts::TABLE . ' WHERE campaign_id = :id AND picture IS NOT NULL', ['id' => $campaign->id]));
        $this->connection->transactional(function (Connection $connection) use ($campaign, &$pictures): void {
            // A landing page never public goes first; one that has been public keeps the campaign (canDelete()).
            $pictures = [...$pictures, ...LandingPages::deleteForCampaign($connection, $campaign->id)];
            $connection->delete(self::TABLE, ['id' => $campaign->id]);
        });
        foreach (array_unique($pictures) as $picture) {
            $this->pictures?->removeUnused($picture);
        }
        $this->log('marketing.campaign_deleted', $campaign, ['name' => $campaign->name], $actor);
    }

    /** Whether a campaign may be deleted: no publication, nobody has opened its links, and its landing page (if any) has never been public. */
    public function canDelete(Campaign $campaign): bool
    {
        return $this->connection->fetchOne('SELECT 1 FROM ' . Publications::TABLE . ' WHERE campaign_id = :id LIMIT 1', ['id' => $campaign->id]) === false
            && !$this->hasClicks($campaign) && !LandingPages::campaignPageWasPublic($this->connection, $campaign->id);
    }

    /**
     * An offer's code changed in the project: the campaigns promoting it follow (a campaign promoting both keeps one).
     * Landing\LandingPages::offerRenamed() calls this with the rest, so a project calls that alone. Gives how many changed.
     */
    public function offerRenamed(string $type, string $from, string $to): int
    {
        if ($from === $to) {
            return 0;
        }

        return $this->connection->transactional(function (Connection $connection) use ($type, $from, $to): int {
            $connection->executeStatement('DELETE FROM ' . self::OFFERS . ' o WHERE o.offer_type = :type AND o.offer_reference = :from AND EXISTS (SELECT 1 FROM ' . self::OFFERS
                . ' n WHERE n.campaign_id = o.campaign_id AND n.offer_type = :type AND n.offer_reference = :to)', ['type' => $type, 'from' => $from, 'to' => $to]);

            return (int) $connection->executeStatement('UPDATE ' . self::OFFERS . ' SET offer_reference = :to WHERE offer_type = :type AND offer_reference = :from', ['type' => $type, 'from' => $from, 'to' => $to]);
        });
    }

    /** Whether a person has opened any of a campaign's links, so its code no longer changes. */
    public function hasClicks(Campaign $campaign): bool
    {
        return $this->connection->fetchOne('SELECT 1 FROM ' . Touches::TABLE . " WHERE campaign_id = :id AND agent = 'person' LIMIT 1", ['id' => $campaign->id]) !== false;
    }

    /** A free code made from a name (or a code to start from): small letters, digits and hyphens, a number added if taken. */
    public function suggestCode(string $name, ?string $except = null): string
    {
        $base = Rows::slug($name, 36);
        if (strlen($base) < 2) {
            $base = 'campaign';
        }
        $code = $base;
        for ($n = 2; $this->taken($code, $except); $n++) {
            $code = rtrim(substr($base, 0, 39 - strlen((string) $n)), '-') . '-' . $n;
        }

        return $code;
    }

    /**
     * A campaign's history: its entries in the activity log, under its code and the codes it had before, newest first.
     *
     * @return list<ActivityEntry>
     */
    public function history(Campaign $campaign, int $limit = 100): array
    {
        if ($this->activity === null) {
            return [];
        }
        $entries = [];
        // A second's grace, for a log that keeps whole seconds.
        $since = $campaign->createdAt->modify('-1 second');
        foreach ([$campaign->code, ...$campaign->formerCodes] as $code) {
            // A code a deleted campaign had may be given again: only what happened since this one was made is its own.
            $entries = [...$entries, ...array_filter($this->activity->recent($limit, null, self::SUBJECT, $code), static fn(ActivityEntry $entry): bool => $entry->at >= $since)];
        }
        usort($entries, static fn(ActivityEntry $a, ActivityEntry $b): int => $b->at <=> $a->at);

        return array_slice($entries, 0, $limit);
    }

    /**
     * Records an entry of a campaign's history (for its adverts, publications and spend too).
     *
     * @param array<string,mixed> $details
     */
    public function log(string $action, Campaign $campaign, array $details, ?Actor $actor): void
    {
        $this->activity?->record($action, ['campaign' => $campaign->code] + $details, self::SUBJECT, $campaign->code, $actor);
    }

    public function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone($this->zone());
    }

    public function zone(): DateTimeZone
    {
        return $this->installation?->timeZone() ?? new DateTimeZone('UTC');
    }

    private function setStatus(Campaign $campaign, string $status, string $action, ?Actor $actor): Campaign
    {
        $this->connection->update(self::TABLE, ['status' => $status, 'updated_at' => Rows::sql($this->now())], ['id' => $campaign->id]);
        $changed = $this->byId($campaign->id) ?? $campaign;
        $this->log($action, $changed, ['status_before' => $campaign->status, 'status' => $status], $actor);

        return $changed;
    }

    private function taken(string $code, ?string $except): bool
    {
        return $code !== $except && (in_array($code, self::RESERVED, true) || $this->connection->fetchOne('SELECT 1 FROM ' . self::TABLE . ' WHERE code = :code', ['code' => $code]) !== false);
    }

    /**
     * What was typed, checked: the row to write and the offers, or every reason it is refused at once.
     *
     * @return array{row:array<string,mixed>,offers:list<array{type:string,reference:string,label:string}>}
     * @throws MarketingRefused
     */
    private function checked(CampaignInput $input, ?Campaign $campaign): array
    {
        $errors = [];
        $name = trim($input->name);
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Type the campaign\'s name, up to 120 characters.';
        }
        $code = strtolower(trim($input->code));
        if ($code === '') {
            $code = $campaign->code ?? $this->suggestCode($name);
        }
        if (preg_match(self::CODE, $code) !== 1) {
            $errors['code'] = 'A code is 2 to 40 small letters, digits and hyphens, starting with a letter or digit, such as spring-sale.';
        } elseif (in_array($code, self::RESERVED, true)) {
            $errors['code'] = 'The code ' . $code . ' is kept for one of the pages: choose another.';
        } elseif ($this->taken($code, $campaign?->code)) {
            $errors['code'] = 'Another campaign has the code ' . $code . '.';
        } elseif ($campaign !== null && $code !== $campaign->code && $this->hasClicks($campaign)) {
            $errors['code'] = 'People have opened this campaign\'s links, so its code stays ' . $campaign->code . '.';
        }
        if (mb_strlen($input->description) > 5000) {
            $errors['description'] = 'The description is up to 5,000 characters.';
        }
        if (mb_strlen($input->objective) > 2000) {
            $errors['objective'] = 'The objective is up to 2,000 characters.';
        }
        $startsAt = $this->typedInstant($input->startsAt, false);
        if ($startsAt === false) {
            $errors['starts_at'] = 'Type the start as a date, such as 2026-12-01, or leave it empty.';
        }
        $endsAt = $this->typedInstant($input->endsAt, true);
        if ($endsAt === false) {
            $errors['ends_at'] = 'Type the end as a date, such as 2026-12-31, or leave it empty.';
        } elseif ($endsAt !== null && $startsAt instanceof DateTimeImmutable && $endsAt <= $startsAt) {
            $errors['ends_at'] = 'The end comes after the start.';
        }
        $budget = null;
        if (trim($input->budget) !== '') {
            $budget = TypedMoney::minor($input->budget);
            if ($budget === null) {
                $errors['budget'] = 'Type the budget as an amount, such as 1500 or 1500.50, or leave it empty for none.';
            }
        }
        $destination = Destination::normalise($input->destination);
        $problem = Destination::problem($destination);
        if ($problem !== null) {
            $errors['destination'] = $problem;
        }
        $promotion = trim($input->promotion);
        if ($promotion !== '' && $promotion !== $campaign?->promotion && !array_key_exists($promotion, $this->project->promotions())) {
            $errors['promotion'] = 'Choose one of the promotions, or none.';
        }
        [$offers, $offerProblem] = $this->checkedOffers($input->offers, $campaign);
        if ($offerProblem !== null) {
            $errors['offers'] = $offerProblem;
        }
        if ($errors !== []) {
            throw new MarketingRefused($errors);
        }

        return ['row' => ['code' => $code, 'name' => $name, 'description' => trim($input->description), 'objective' => trim($input->objective),
            'starts_at' => $startsAt instanceof DateTimeImmutable ? Rows::sql($startsAt) : null, 'ends_at' => $endsAt instanceof DateTimeImmutable ? Rows::sql($endsAt) : null,
            'budget_minor' => $budget, 'destination' => $destination, 'promotion' => $promotion === '' ? null : $promotion], 'offers' => $offers];
    }

    /**
     * The offers chosen, each known to the project (or already on the campaign), with their labels now.
     *
     * @param list<string> $keys
     * @return array{0:list<array{type:string,reference:string,label:string}>,1:?string}
     */
    private function checkedOffers(array $keys, ?Campaign $campaign): array
    {
        $kept = [];
        foreach ($campaign === null ? [] : $this->offers($campaign->id) as $offer) {
            $kept[$offer->key()] = $offer;
        }
        $offers = [];
        foreach (array_values(array_unique($keys)) as $key) {
            $parts = explode(':', $key, 2);
            $offer = count($parts) === 2 ? $this->project->offer($parts[0], $parts[1]) : null;
            if ($offer !== null) {
                $offers[] = ['type' => $offer->type, 'reference' => $offer->reference, 'label' => mb_substr($offer->label, 0, 200)];
            } elseif (isset($kept[$key])) {
                $offers[] = ['type' => $kept[$key]->type, 'reference' => $kept[$key]->reference, 'label' => $kept[$key]->label];
            } else {
                return [[], 'Choose the offers from the list: one of them is not there any more.'];
            }
        }

        return [$offers, null];
    }

    /** @param list<array{type:string,reference:string,label:string}> $offers */
    private function saveOffers(int $campaignId, array $offers): void
    {
        $this->connection->delete(self::OFFERS, ['campaign_id' => $campaignId]);
        foreach ($offers as $position => $offer) {
            $this->connection->insert(self::OFFERS, ['campaign_id' => $campaignId, 'offer_type' => $offer['type'], 'offer_reference' => $offer['reference'], 'label' => $offer['label'], 'position' => $position]);
        }
    }

    /**
     * A date or a date and time as typed, in the installation's time zone: null when empty, false when it cannot be read.
     * A date alone is the start of that day, or for an end the last second of it.
     */
    private function typedInstant(string $typed, bool $end): DateTimeImmutable|false|null
    {
        $typed = trim($typed);
        if ($typed === '') {
            return null;
        }
        $zone = $this->zone();
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $typed, $zone);
        if ($date !== false && $date->format('Y-m-d') === $typed) {
            return $end ? $date->setTime(23, 59, 59) : $date;
        }
        foreach (['!Y-m-d\TH:i', '!Y-m-d H:i', '!Y-m-d\TH:i:s'] as $format) {
            $at = DateTimeImmutable::createFromFormat($format, $typed, $zone);
            if ($at !== false && $at->format(substr($format, 1)) === $typed) {
                return $at;
            }
        }

        return false;
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private function where(?string $status, string $search): array
    {
        $conditions = ['TRUE'];
        $params = [];
        $now = Rows::sql($this->now());
        $condition = match ($status) {
            'current' => "c.status <> 'archived'",
            'draft', 'paused', 'archived' => 'c.status = :status',
            'scheduled' => "c.status = 'active' AND c.starts_at > :now",
            'active' => "c.status = 'active' AND (c.starts_at IS NULL OR c.starts_at <= :now) AND (c.ends_at IS NULL OR c.ends_at > :now)",
            'ended' => "c.status = 'active' AND (c.starts_at IS NULL OR c.starts_at <= :now) AND c.ends_at <= :now",
            default => null,
        };
        if ($condition !== null) {
            $conditions[] = $condition;
            if (str_contains($condition, ':status')) {
                $params['status'] = $status;
            }
            if (str_contains($condition, ':now')) {
                $params['now'] = $now;
            }
        }
        $search = trim($search);
        if ($search !== '') {
            $conditions[] = '(c.name ILIKE :like OR c.code ILIKE :like OR c.description ILIKE :like)';
            $params['like'] = Rows::like($search);
        }

        return [implode(' AND ', $conditions), $params];
    }

    private function shownStatus(string $status, ?DateTimeImmutable $startsAt, ?DateTimeImmutable $endsAt, DateTimeImmutable $now): string
    {
        if ($status !== 'active') {
            return $status;
        }
        if ($startsAt !== null && $startsAt > $now) {
            return 'scheduled';
        }

        return $endsAt !== null && $endsAt <= $now ? 'ended' : 'active';
    }

    /** @param array<string,mixed> $row */
    private function campaign(array $row): Campaign
    {
        $zone = $this->zone();
        $former = json_decode(Rows::text($row['former_codes'] ?? '[]'), true);

        return new Campaign(Rows::int($row['id']), Rows::text($row['code']), Rows::text($row['name']), Rows::text($row['description']), Rows::text($row['objective']), Rows::text($row['status']),
            Rows::instant($row['starts_at'], $zone), Rows::instant($row['ends_at'], $zone), Rows::nullableInt($row['budget_minor']), Rows::text($row['destination']),
            Rows::nullableText($row['promotion']), Rows::nullableText($row['created_by']), Rows::instant($row['created_at'], $zone) ?? $this->now(),
            Rows::instant($row['updated_at'], $zone) ?? $this->now(), is_array($former) ? array_values(array_map('strval', $former)) : [], Rows::nullableText($row['status_before_archive']),
            Rows::nullableText($row['origin_site'] ?? null));
    }
}
