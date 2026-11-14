<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use CattoPHP\Activity\Actor;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * A campaign's publications, each a place it was published with its own tracking link: the list, a suggested code (the
 * channel's prefix and the next number: fb1, fb2), making and changing one, retiring and restoring it, and deleting one
 * whose link nobody has opened. Each change is in its campaign's history. What a person types is refused in plain words
 * by its field (MarketingRefused).
 */
final class Publications
{
    public const TABLE = 'cattophp_marketing_publications';
    public const CODE = '/^[a-z0-9]{1,12}$/';

    /** Codes no publication may have, since its page's address would be another page's (.../publications/new). */
    public const RESERVED = ['new'];

    public function __construct(
        private readonly Connection $connection,
        private readonly Campaigns $campaigns,
        private readonly Channels $channels,
        private readonly Adverts $adverts,
    ) {
    }

    /** @return list<Publication> a campaign's publications, oldest first, the retired after the others unless left out */
    public function list(int $campaignId, bool $withRetired = true): array
    {
        return array_map($this->publication(...), $this->connection->fetchAllAssociative(
            'SELECT * FROM ' . self::TABLE . ' WHERE campaign_id = :id' . ($withRetired ? '' : ' AND retired_at IS NULL') . ' ORDER BY retired_at IS NOT NULL, created_at, id',
            ['id' => $campaignId],
        ));
    }

    /** A campaign's publication by its code. */
    public function get(Campaign $campaign, string $code): ?Publication
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE campaign_id = :id AND code = :code', ['id' => $campaign->id, 'code' => strtolower(trim($code))]);

        return $row === false ? null : $this->publication($row);
    }

    public function byId(int $id): ?Publication
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE id = :id', ['id' => $id]);

        return $row === false ? null : $this->publication($row);
    }

    /** @return list<Publication> the publications that stand for something of the project's, such as one mailing message */
    public function forSource(string $type, string $reference): array
    {
        return array_map($this->publication(...), $this->connection->fetchAllAssociative('SELECT * FROM ' . self::TABLE . ' WHERE source_type = :type AND source_reference = :reference ORDER BY id',
            ['type' => $type, 'reference' => $reference]));
    }

    /** The code suggested for a campaign's next publication on a channel: its prefix and the next number free (fb1, fb2). */
    public function suggestCode(Campaign $campaign, string $channelKey): string
    {
        $prefix = $this->channels->get($channelKey)->linkPrefix ?? 'p';
        $taken = array_map('strval', $this->connection->fetchFirstColumn('SELECT code FROM ' . self::TABLE . ' WHERE campaign_id = :id', ['id' => $campaign->id]));
        $highest = 0;
        foreach ($taken as $code) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $code, $match) === 1) {
                $highest = max($highest, (int) $match[1]);
            }
        }
        for ($n = $highest + 1; in_array($prefix . $n, $taken, true); $n++);

        return $prefix . $n;
    }

    /**
     * A new publication of a campaign. Its code is suggested when none is typed.
     *
     * @throws MarketingRefused
     */
    public function create(string $campaignCode, PublicationInput $input, ?Actor $actor = null): Publication
    {
        $campaign = $this->campaigns->require($campaignCode);
        $row = $this->checked($campaign, $input, null);
        $now = Rows::sql($this->campaigns->now());
        $this->connection->insert(self::TABLE, $row + ['campaign_id' => $campaign->id, 'source_type' => $input->sourceType, 'source_reference' => $input->sourceReference,
            'created_by' => $actor?->id, 'created_at' => $now, 'updated_at' => $now]);
        $publication = $this->get($campaign, $row['code']) ?? throw MarketingRefused::because('The publication could not be read back.');
        $this->campaigns->log('marketing.publication_created', $campaign, ['publication' => $publication->code, 'channel' => $publication->channelKey, 'place' => $publication->place], $actor);

        return $publication;
    }

    /**
     * A publication changed as typed. Its code changes only while nobody has opened its link.
     *
     * @throws MarketingRefused
     */
    public function change(string $campaignCode, string $code, PublicationInput $input, ?Actor $actor = null): Publication
    {
        $campaign = $this->campaigns->require($campaignCode);
        $publication = $this->get($campaign, $code) ?? throw MarketingRefused::because('There is no such publication.');
        $row = $this->checked($campaign, $input, $publication);
        $this->connection->update(self::TABLE, $row + ['updated_at' => Rows::sql($this->campaigns->now())], ['id' => $publication->id]);
        $changed = $this->byId($publication->id) ?? $publication;
        $details = ['publication' => $changed->code, 'channel' => $changed->channelKey, 'place' => $changed->place];
        if ($changed->code !== $publication->code) {
            $details['code_before'] = $publication->code;
        }
        $this->campaigns->log('marketing.publication_changed', $campaign, $details, $actor);

        return $changed;
    }

    /** Retires a publication: kept with its clicks, its link still leading where it did. */
    public function retire(string $campaignCode, string $code, ?Actor $actor = null): Publication
    {
        [$campaign, $publication] = $this->find($campaignCode, $code);
        if ($publication->isRetired()) {
            throw MarketingRefused::because('This publication is already retired.');
        }
        $this->connection->update(self::TABLE, ['retired_at' => Rows::sql($this->campaigns->now())], ['id' => $publication->id]);
        $this->campaigns->log('marketing.publication_retired', $campaign, ['publication' => $publication->code], $actor);

        return $this->byId($publication->id) ?? $publication;
    }

    public function restore(string $campaignCode, string $code, ?Actor $actor = null): Publication
    {
        [$campaign, $publication] = $this->find($campaignCode, $code);
        if (!$publication->isRetired()) {
            throw MarketingRefused::because('This publication is not retired.');
        }
        $this->connection->update(self::TABLE, ['retired_at' => null], ['id' => $publication->id]);
        $this->campaigns->log('marketing.publication_restored', $campaign, ['publication' => $publication->code], $actor);

        return $this->byId($publication->id) ?? $publication;
    }

    /**
     * Deletes a publication whose link nobody has opened.
     *
     * @throws MarketingRefused when its link has been opened: it is retired instead
     */
    public function delete(string $campaignCode, string $code, ?Actor $actor = null): void
    {
        [$campaign, $publication] = $this->find($campaignCode, $code);
        if ($this->hasTouches($publication)) {
            throw MarketingRefused::because('This publication\'s link has been opened, so it is kept: retire it instead.');
        }
        $this->connection->delete(self::TABLE, ['id' => $publication->id]);
        $this->campaigns->log('marketing.publication_deleted', $campaign, ['publication' => $publication->code], $actor);
    }

    /** Whether a person has opened the publication's link, so its code no longer changes. */
    public function hasClicks(Publication $publication): bool
    {
        return $this->connection->fetchOne('SELECT 1 FROM ' . Touches::TABLE . " WHERE publication_id = :id AND agent = 'person' LIMIT 1", ['id' => $publication->id]) !== false;
    }

    /** Whether anything has opened the publication's link (a person, a link preview, a crawler). */
    public function hasTouches(Publication $publication): bool
    {
        return $this->connection->fetchOne('SELECT 1 FROM ' . Touches::TABLE . ' WHERE publication_id = :id LIMIT 1', ['id' => $publication->id]) !== false;
    }

    /** @return array{0:Campaign,1:Publication} */
    private function find(string $campaignCode, string $code): array
    {
        $campaign = $this->campaigns->require($campaignCode);

        return [$campaign, $this->get($campaign, $code) ?? throw MarketingRefused::because('There is no such publication.')];
    }

    /**
     * What was typed, checked: the columns to write, or every reason it is refused at once.
     *
     * @return array{channel_key:string,advert_id:?int,code:string,place:string,published_on:?string,published_address:?string,cost_minor:int,destination:?string,notes:string}
     * @throws MarketingRefused
     */
    private function checked(Campaign $campaign, PublicationInput $input, ?Publication $publication): array
    {
        $errors = [];
        $channel = $this->channels->get($input->channel);
        if ($channel === null || (!$channel->active && $channel->key !== $publication?->channelKey)) {
            $errors['channel'] = 'Choose the channel it was published on.';
        }
        $advertId = null;
        if ($input->advert !== '') {
            $advert = $this->adverts->get($input->advert, $campaign);
            if ($advert === null || ($advert->isRetired() && $advert->id !== $publication?->advertId)) {
                $errors['advert'] = 'Choose one of the campaign\'s adverts, or none.';
            } else {
                $advertId = $advert->id;
            }
        }
        $code = strtolower(trim($input->code));
        if ($code === '') {
            $code = $publication->code ?? ($channel === null ? '' : $this->suggestCode($campaign, $channel->key));
        }
        if (preg_match(self::CODE, $code) !== 1) {
            $errors['code'] = 'A publication\'s code is 1 to 12 small letters and digits, such as fb1.';
        } elseif (in_array($code, self::RESERVED, true)) {
            $errors['code'] = 'The code ' . $code . ' is kept for one of the pages: choose another.';
        } elseif ($code !== $publication?->code && $this->get($campaign, $code) !== null) {
            $errors['code'] = 'Another publication of this campaign has the code ' . $code . '.';
        } elseif ($publication !== null && $code !== $publication->code && $this->hasClicks($publication)) {
            $errors['code'] = 'People have opened this publication\'s link, so its code stays ' . $publication->code . '.';
        }
        $place = trim($input->place);
        if (mb_strlen($place) > 200) {
            $errors['place'] = 'The place is up to 200 characters.';
        }
        $publishedOn = null;
        if (trim($input->publishedOn) !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($input->publishedOn));
            if ($date === false || $date->format('Y-m-d') !== trim($input->publishedOn)) {
                $errors['published_on'] = 'Type the date it was published, such as 2026-12-01, or leave it empty.';
            } else {
                $publishedOn = $date->format('Y-m-d');
            }
        }
        $address = trim($input->publishedAddress);
        if ($address !== '' && (strlen($address) > Destination::LONGEST || !Destination::isOutside($address) || preg_match('/\s/', $address) === 1)) {
            $errors['published_address'] = 'Type the whole address where it can be seen, starting with https://, or leave it empty.';
        }
        $cost = 0;
        if (trim($input->cost) !== '') {
            $cost = TypedMoney::minor($input->cost);
            if ($cost === null) {
                $errors['cost'] = 'Type what it cost as an amount, such as 250 or 250.50, or leave it empty for nothing.';
                $cost = 0;
            }
        }
        $destination = trim($input->destination);
        if ($destination !== '') {
            $problem = Destination::problem($destination);
            if ($problem !== null) {
                $errors['destination'] = $problem;
            }
        }
        if (mb_strlen($input->notes) > 5000) {
            $errors['notes'] = 'The notes are up to 5,000 characters.';
        }
        if ($errors !== []) {
            throw new MarketingRefused($errors);
        }

        return ['channel_key' => $channel->key ?? '', 'advert_id' => $advertId, 'code' => $code, 'place' => $place, 'published_on' => $publishedOn, 'published_address' => $address === '' ? null : $address,
            'cost_minor' => $cost, 'destination' => $destination === '' ? null : $destination, 'notes' => trim($input->notes)];
    }

    /** @param array<string,mixed> $row */
    private function publication(array $row): Publication
    {
        $zone = $this->campaigns->zone();

        return new Publication(Rows::int($row['id']), Rows::int($row['campaign_id']), Rows::text($row['channel_key']), Rows::nullableInt($row['advert_id']), Rows::text($row['code']),
            Rows::text($row['place']), Rows::date($row['published_on'], $zone), Rows::nullableText($row['published_address']), Rows::int($row['cost_minor']), Rows::nullableText($row['destination']),
            Rows::text($row['notes']), Rows::nullableText($row['source_type']), Rows::nullableText($row['source_reference']), Rows::instant($row['retired_at'], $zone),
            Rows::instant($row['created_at'], $zone) ?? $this->campaigns->now(), Rows::instant($row['updated_at'], $zone) ?? $this->campaigns->now());
    }
}
