<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use CattoPHP\Activity\Actor;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * A campaign's spend: its other costs (those of no publication), added and removed on its page, and its whole spend, its
 * publications' costs and these together. Each change is in its campaign's history.
 */
final class Spend
{
    public const TABLE = 'cattophp_marketing_spend';

    public function __construct(private readonly Connection $connection, private readonly Campaigns $campaigns)
    {
    }

    /** @return list<SpendItem> a campaign's other costs, the newest first */
    public function list(int $campaignId): array
    {
        $zone = $this->campaigns->zone();

        return array_map(fn(array $row): SpendItem => new SpendItem(Rows::int($row['id']), Rows::text($row['reference']), Rows::int($row['campaign_id']),
            Rows::date($row['spent_on'], $zone) ?? $this->campaigns->now(), Rows::int($row['amount_minor']), Rows::text($row['note']), Rows::nullableText($row['created_by']),
            Rows::instant($row['created_at'], $zone) ?? $this->campaigns->now()),
            $this->connection->fetchAllAssociative('SELECT * FROM ' . self::TABLE . ' WHERE campaign_id = :id ORDER BY spent_on DESC, id DESC', ['id' => $campaignId]));
    }

    /**
     * A cost added to a campaign, as typed: its date (2026-12-01; today when empty), amount and note.
     *
     * @throws MarketingRefused
     */
    public function add(string $campaignCode, string $spentOn, string $amount, string $note, ?Actor $actor = null): SpendItem
    {
        $campaign = $this->campaigns->require($campaignCode);
        $errors = [];
        $spentOn = trim($spentOn);
        $date = $spentOn === '' ? $this->campaigns->now() : DateTimeImmutable::createFromFormat('!Y-m-d', $spentOn, $this->campaigns->zone());
        if ($date === false || ($spentOn !== '' && $date->format('Y-m-d') !== $spentOn)) {
            $errors['spent_on'] = 'Type the date it was spent, such as 2026-12-01.';
        }
        $minor = TypedMoney::minor($amount);
        if ($minor === null || $minor === 0) {
            $errors['amount'] = 'Type what was spent, such as 500 or 499.99.';
        }
        $note = trim($note);
        if (mb_strlen($note) > 300) {
            $errors['note'] = 'The note is up to 300 characters.';
        }
        if ($errors !== [] || $date === false || $minor === null) {
            throw new MarketingRefused($errors);
        }
        $reference = Rows::reference();
        $this->connection->insert(self::TABLE, ['reference' => $reference, 'campaign_id' => $campaign->id, 'spent_on' => $date->format('Y-m-d'), 'amount_minor' => $minor, 'note' => $note,
            'created_by' => $actor?->id, 'created_at' => Rows::sql($this->campaigns->now())]);
        $this->campaigns->log('marketing.spend_added', $campaign, ['amount_minor' => $minor, 'spent_on' => $date->format('Y-m-d'), 'note' => $note], $actor);
        foreach ($this->list($campaign->id) as $item) {
            if ($item->reference === $reference) {
                return $item;
            }
        }

        throw MarketingRefused::because('The cost could not be read back.');
    }

    /** Removes one of a campaign's other costs, by its reference. */
    public function remove(string $campaignCode, string $reference, ?Actor $actor = null): void
    {
        $campaign = $this->campaigns->require($campaignCode);
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE campaign_id = :id AND reference = :reference', ['id' => $campaign->id, 'reference' => strtolower(trim($reference))]);
        if ($row === false) {
            throw MarketingRefused::because('There is no such cost.');
        }
        $this->connection->delete(self::TABLE, ['id' => $row['id']]);
        $this->campaigns->log('marketing.spend_removed', $campaign, ['amount_minor' => Rows::int($row['amount_minor']), 'spent_on' => substr(Rows::text($row['spent_on']), 0, 10), 'note' => Rows::text($row['note'])], $actor);
    }

    /** A campaign's other costs together, in minor units. */
    public function otherTotal(int $campaignId): int
    {
        return (int) $this->connection->fetchOne('SELECT COALESCE(SUM(amount_minor), 0) FROM ' . self::TABLE . ' WHERE campaign_id = :id', ['id' => $campaignId]);
    }

    /** A campaign's whole spend: its publications' costs and its other costs, in minor units. */
    public function total(int $campaignId): int
    {
        return $this->otherTotal($campaignId)
            + (int) $this->connection->fetchOne('SELECT COALESCE(SUM(cost_minor), 0) FROM ' . Publications::TABLE . ' WHERE campaign_id = :id', ['id' => $campaignId]);
    }
}
