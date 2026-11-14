<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use CattoPHP\Activity\Actor;
use Doctrine\DBAL\Connection;

/**
 * A campaign's adverts: the list, making and changing one (its words, an outside address, a picture uploaded and kept),
 * retiring and restoring it, deleting one no publication uses, and its picture read back for a page. Each change is in
 * its campaign's history. What a person types is refused in plain words by its field (MarketingRefused).
 */
final class Adverts
{
    public const TABLE = 'cattophp_marketing_adverts';

    public function __construct(
        private readonly Connection $connection,
        private readonly Campaigns $campaigns,
        private readonly Pictures $pictures,
        private readonly MarketingSettings $settings = new MarketingSettings(),
    ) {
    }

    /** @return list<Advert> a campaign's adverts, oldest first, the retired after the others unless left out */
    public function list(int $campaignId, bool $withRetired = true): array
    {
        return array_map($this->advert(...), $this->connection->fetchAllAssociative(
            'SELECT a.*, (SELECT COUNT(*) FROM ' . Publications::TABLE . ' p WHERE p.advert_id = a.id) AS publications FROM ' . self::TABLE . ' a
              WHERE a.campaign_id = :id' . ($withRetired ? '' : ' AND a.retired_at IS NULL') . ' ORDER BY a.retired_at IS NOT NULL, a.created_at, a.id',
            ['id' => $campaignId],
        ));
    }

    /** An advert by its reference; when a campaign is given, only one of that campaign (another's is simply not found). */
    public function get(string $reference, ?Campaign $of = null): ?Advert
    {
        $row = $this->connection->fetchAssociative('SELECT a.*, (SELECT COUNT(*) FROM ' . Publications::TABLE . ' p WHERE p.advert_id = a.id) AS publications FROM ' . self::TABLE . ' a WHERE a.reference = :reference',
            ['reference' => strtolower(trim($reference))]);
        if ($row === false) {
            return null;
        }
        $advert = $this->advert($row);

        return $of === null || $advert->campaignId === $of->id ? $advert : null;
    }

    public function byId(int $id): ?Advert
    {
        $row = $this->connection->fetchAssociative('SELECT a.*, (SELECT COUNT(*) FROM ' . Publications::TABLE . ' p WHERE p.advert_id = a.id) AS publications FROM ' . self::TABLE . ' a WHERE a.id = :id', ['id' => $id]);

        return $row === false ? null : $this->advert($row);
    }

    /**
     * A new advert of a campaign.
     *
     * @throws MarketingRefused
     */
    public function create(string $campaignCode, AdvertInput $input, ?Actor $actor = null): Advert
    {
        $campaign = $this->campaigns->require($campaignCode);
        $row = $this->checked($input, null);
        $reference = Rows::reference();
        $now = Rows::sql($this->campaigns->now());
        $this->connection->insert(self::TABLE, $row + ['reference' => $reference, 'campaign_id' => $campaign->id, 'created_by' => $actor?->id, 'created_at' => $now, 'updated_at' => $now]);
        $advert = $this->get($reference) ?? throw MarketingRefused::because('The advert could not be read back.');
        $this->campaigns->log('marketing.advert_created', $campaign, ['advert' => $advert->name, 'kind' => $advert->kind], $actor);

        return $advert;
    }

    /**
     * An advert changed as typed; a new picture replaces the one there was.
     *
     * @throws MarketingRefused
     */
    public function change(string $reference, AdvertInput $input, ?Actor $actor = null, ?Campaign $of = null): Advert
    {
        $advert = $this->get($reference, $of) ?? throw MarketingRefused::because('There is no such advert.');
        $row = $this->checked($input, $advert);
        $this->connection->update(self::TABLE, $row + ['updated_at' => Rows::sql($this->campaigns->now())], ['id' => $advert->id]);
        if ($advert->picture !== null && $advert->picture !== $row['picture']) {
            $this->pictures->removeUnused($advert->picture);
        }
        $changed = $this->byId($advert->id) ?? $advert;
        $this->campaigns->log('marketing.advert_changed', $this->campaignOf($changed), ['advert' => $changed->name], $actor);

        return $changed;
    }

    /** Retires an advert: kept with its publications, no longer offered for new ones. */
    public function retire(string $reference, ?Actor $actor = null, ?Campaign $of = null): Advert
    {
        $advert = $this->get($reference, $of) ?? throw MarketingRefused::because('There is no such advert.');
        if ($advert->isRetired()) {
            throw MarketingRefused::because('This advert is already retired.');
        }
        $this->connection->update(self::TABLE, ['retired_at' => Rows::sql($this->campaigns->now())], ['id' => $advert->id]);
        $this->campaigns->log('marketing.advert_retired', $this->campaignOf($advert), ['advert' => $advert->name], $actor);

        return $this->byId($advert->id) ?? $advert;
    }

    /** Offers a retired advert again. */
    public function restore(string $reference, ?Actor $actor = null, ?Campaign $of = null): Advert
    {
        $advert = $this->get($reference, $of) ?? throw MarketingRefused::because('There is no such advert.');
        if (!$advert->isRetired()) {
            throw MarketingRefused::because('This advert is not retired.');
        }
        $this->connection->update(self::TABLE, ['retired_at' => null], ['id' => $advert->id]);
        $this->campaigns->log('marketing.advert_restored', $this->campaignOf($advert), ['advert' => $advert->name], $actor);

        return $this->byId($advert->id) ?? $advert;
    }

    /**
     * Deletes an advert no publication uses, and its picture when no other advert shares it.
     *
     * @throws MarketingRefused when a publication uses it: it is retired instead
     */
    public function delete(string $reference, ?Actor $actor = null, ?Campaign $of = null): void
    {
        $advert = $this->get($reference, $of) ?? throw MarketingRefused::because('There is no such advert.');
        if ($advert->inUse()) {
            throw MarketingRefused::because('This advert has been published, so it is kept: retire it instead.');
        }
        $campaign = $this->campaignOf($advert);
        $this->connection->delete(self::TABLE, ['id' => $advert->id]);
        if ($advert->picture !== null) {
            $this->pictures->removeUnused($advert->picture);
        }
        $this->campaigns->log('marketing.advert_deleted', $campaign, ['advert' => $advert->name], $actor);
    }

    /** An advert's picture read back, for a page that shows it to a person with the view permission. */
    public function picture(string $reference, ?Campaign $of = null): ?Picture
    {
        return $this->pictures->read($this->get($reference, $of)?->picture);
    }

    private function campaignOf(Advert $advert): Campaign
    {
        return $this->campaigns->byId($advert->campaignId) ?? throw MarketingRefused::because('There is no such campaign.');
    }

    /**
     * What was typed, checked: the columns to write, the picture kept, or every reason it is refused at once.
     *
     * @return array{name:string,kind:string,headline:string,words:string,media_address:?string,picture:?string,notes:string}
     * @throws MarketingRefused
     */
    private function checked(AdvertInput $input, ?Advert $advert): array
    {
        $errors = [];
        $name = trim($input->name);
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Type a name for the advert, up to 120 characters, such as Spring picture.';
        }
        if (!array_key_exists($input->kind, Advert::KINDS)) {
            $errors['kind'] = 'Choose words, a picture or a video.';
        }
        if (mb_strlen($input->headline) > 200) {
            $errors['headline'] = 'The headline is up to 200 characters.';
        }
        if (mb_strlen($input->words) > 5000) {
            $errors['words'] = 'The words are up to 5,000 characters.';
        }
        if (mb_strlen($input->notes) > 5000) {
            $errors['notes'] = 'The notes are up to 5,000 characters.';
        }
        $address = trim($input->mediaAddress);
        if ($address !== '' && (strlen($address) > Destination::LONGEST || !Destination::isOutside($address) || preg_match('/\s/', $address) === 1)) {
            $errors['media_address'] = 'Type a whole address starting with https://, such as the video\'s.';
        } elseif ($address === '' && $input->kind === 'video') {
            $errors['media_address'] = 'Type the video\'s address, starting with https://.';
        }
        $picture = $input->removePicture ? null : $advert?->picture;
        if ($input->uploadProblem !== null) {
            $errors['picture'] = $input->uploadProblem;
        } elseif ($input->picturePath !== null && $errors === []) {
            try {
                $picture = $this->pictures->store($input->picturePath, $this->settings->pictureLargest());
            } catch (MarketingRefused $refused) {
                $errors += $refused->errors();
            }
        }
        if (!isset($errors['picture']) && $input->kind === 'picture' && $picture === null && $input->picturePath === null) {
            $errors['picture'] = 'Choose the advert\'s picture: a JPEG, PNG or WebP.';
        }
        if ($input->kind === 'words' && trim($input->headline) === '' && trim($input->words) === '' && !isset($errors['headline']) && !isset($errors['words'])) {
            $errors['words'] = 'Type the advert\'s headline or words.';
        }
        if ($errors !== []) {
            throw new MarketingRefused($errors);
        }

        return ['name' => $name, 'kind' => $input->kind, 'headline' => trim($input->headline), 'words' => trim($input->words), 'media_address' => $address === '' ? null : $address,
            'picture' => $picture, 'notes' => trim($input->notes)];
    }

    /** @param array<string,mixed> $row */
    private function advert(array $row): Advert
    {
        $zone = $this->campaigns->zone();

        return new Advert(Rows::int($row['id']), Rows::text($row['reference']), Rows::int($row['campaign_id']), Rows::text($row['name']), Rows::text($row['kind']), Rows::text($row['headline']),
            Rows::text($row['words']), Rows::nullableText($row['media_address']), Rows::nullableText($row['picture']), Rows::text($row['notes']), Rows::instant($row['retired_at'], $zone),
            Rows::instant($row['created_at'], $zone) ?? $this->campaigns->now(), Rows::instant($row['updated_at'], $zone) ?? $this->campaigns->now(), Rows::int($row['publications'] ?? 0));
    }
}
