<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Mail;

use CattoPHP\Activity\Actor;
use CattoPHP\Mailing\Campaigns as MailingMessages;
use CattoPHP\Mailing\MailingLists;
use CattoPHP\Marketing\Adverts;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Channels;
use CattoPHP\Marketing\MarketingRefused;
use CattoPHP\Marketing\Publication;
use CattoPHP\Marketing\PublicationInput;
use CattoPHP\Marketing\Publications;
use CattoPHP\Marketing\Rows;
use Doctrine\DBAL\Connection;

/**
 * A mailing message (a campaign of the mail module, under Mailing > Messages) counted for a Campaign Marketing Manager
 * campaign as one of its publications, so the clicks on its links are the campaign's touches (Mail\MailedLinks). The
 * publication is on the channel email (other, if email is retired), coded as the channel's next em<N>, its place the
 * message's lists by name, standing for the message (source mailing, the message's id: never in an address), and
 * published on the day the message started sending. Tying a message to another campaign unties it from the first;
 * untying deletes its publication while nothing has opened its links, and retires it otherwise. Each tie and untie is
 * in the campaign's history (marketing.publication.tied, .untied).
 */
final class MailingPublications
{
    /** What a mailing message's publication stands for (Publication::$sourceType). */
    public const SOURCE = 'mailing';

    public function __construct(
        private readonly Connection $connection,
        private readonly Campaigns $campaigns,
        private readonly Publications $publications,
        private readonly Channels $channels,
        private readonly MailingMessages $messages,
        private readonly MailingLists $lists,
    ) {
    }

    /**
     * Counts a message for a campaign: its publication made, or changed when it has one in that campaign already (put
     * back if it was retired), its place in words given or, when none is given, its lists' names.
     *
     * @throws MarketingRefused when the message or the campaign is not there, or the campaign is archived
     */
    public function tie(int $message, string $campaignCode, string $place = '', ?Actor $actor = null): Publication
    {
        $found = $this->messages->find($message) ?? throw MarketingRefused::because('There is no such mailing message.');
        $campaign = $this->campaigns->require($campaignCode);
        if ($campaign->status === 'archived') {
            throw MarketingRefused::because('The campaign ' . $campaign->name . ' is archived, so nothing new counts for it: choose another, or none.');
        }
        $place = $this->place($found, $place);
        $current = $this->forMessage($message);
        if ($current !== null && $current->campaignId === $campaign->id) {
            if ($current->place !== $place) {
                $current = $this->publications->change($campaign->code, $current->code, $this->changedPlace($current, $place), $actor);
            }

            return $this->refresh($current);
        }
        if ($current !== null) {
            $this->untie($message, $actor);
        }
        $retired = null;
        foreach ($this->publications->forSource(self::SOURCE, (string) $message) as $publication) {
            if ($publication->campaignId === $campaign->id) {
                $retired = $publication;
            }
        }
        if ($retired !== null) {
            $publication = $this->publications->restore($campaign->code, $retired->code, $actor);
            if ($publication->place !== $place) {
                $publication = $this->publications->change($campaign->code, $publication->code, $this->changedPlace($publication, $place), $actor);
            }
        } else {
            $channel = $this->channels->get('email');
            $channelKey = $channel !== null && $channel->active ? 'email' : 'other';
            $code = $this->publications->suggestCode($campaign, 'email');
            $publication = $this->publications->create($campaign->code, new PublicationInput($channelKey, code: $code, place: $place, sourceType: self::SOURCE,
                sourceReference: (string) $message), $actor);
        }
        $this->campaigns->log('marketing.publication.tied', $campaign, ['message' => self::name($found), 'publication' => $publication->code], $actor);

        return $this->refresh($publication);
    }

    /** No longer counts a message for its campaign: its publication deleted while nothing has opened its links, else retired. */
    public function untie(int $message, ?Actor $actor = null): void
    {
        $publication = $this->forMessage($message);
        if ($publication === null) {
            return;
        }
        $campaign = $this->campaigns->byId($publication->campaignId) ?? throw MarketingRefused::because('The campaign the message counts for is not there.');
        if ($this->publications->hasTouches($publication)) {
            $this->publications->retire($campaign->code, $publication->code, $actor);
        } else {
            $this->publications->delete($campaign->code, $publication->code, $actor);
        }
        $found = $this->messages->find($message);
        $this->campaigns->log('marketing.publication.untied', $campaign, ['message' => $found === null ? '' : self::name($found), 'publication' => $publication->code], $actor);
    }

    /** The publication a message counts as now (not retired), or null. */
    public function forMessage(int $message): ?Publication
    {
        foreach ($this->publications->forSource(self::SOURCE, (string) $message) as $publication) {
            if (!$publication->isRetired()) {
                return $publication;
            }
        }

        return null;
    }

    /** A message's publication with the day it was published made the day the message started sending, once it has. */
    public function refresh(Publication $publication): Publication
    {
        if ($publication->sourceType !== self::SOURCE || $publication->sourceReference === null || preg_match('/^[1-9][0-9]*$/', $publication->sourceReference) !== 1) {
            return $publication;
        }
        $message = $this->messages->find((int) $publication->sourceReference);
        $started = is_string($message['started_at'] ?? null) ? Rows::instant($message['started_at'], $this->campaigns->zone()) : null;
        $day = $started?->setTimezone($this->campaigns->zone())->format('Y-m-d');
        if ($day === null || $day === $publication->publishedOn?->format('Y-m-d')) {
            return $publication;
        }
        $this->connection->update(Publications::TABLE, ['published_on' => $day], ['id' => $publication->id]);

        return $this->publications->byId($publication->id) ?? $publication;
    }

    /**
     * The place in words: as given, else the message's lists by name, else its own name; at most 200 characters.
     *
     * @param array<string,mixed> $message
     */
    private function place(array $message, string $given): string
    {
        $place = trim($given);
        if ($place === '') {
            $names = [];
            foreach (is_array($message['list_ids'] ?? null) ? $message['list_ids'] : [] as $id) {
                $list = is_int($id) ? $this->lists->find($id) : null;
                $name = is_string($list['name'] ?? null) ? trim($list['name']) : '';
                if ($name !== '' && !in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
            $place = $names !== [] ? implode(', ', $names) : self::name($message);
        }

        return mb_substr($place, 0, 200);
    }

    private function changedPlace(Publication $publication, string $place): PublicationInput
    {
        $input = PublicationInput::of($publication, null);
        $advert = $publication->advertId === null ? '' : (string) $this->connection->fetchOne('SELECT reference FROM ' . Adverts::TABLE . ' WHERE id = :id', ['id' => $publication->advertId]);

        return new PublicationInput($input->channel, $advert, $input->code, $place, $input->publishedOn, $input->publishedAddress, $input->cost, $input->destination, $input->notes,
            $input->sourceType, $input->sourceReference);
    }

    /** @param array<string,mixed> $message */
    private static function name(array $message): string
    {
        return is_string($message['name'] ?? null) ? mb_substr($message['name'], 0, 80) : '';
    }
}
