<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use CattoPHP\Ui\IconLibrary;
use Doctrine\DBAL\Connection;

/**
 * The channels campaigns are published on, as Marketing > Settings shows them: the usual ones given at the start, and
 * those the owner adds, renames, gives another prefix or icon, puts in order and retires. One in use is retired, never
 * deleted, so its publications keep it; one never used may be deleted. Each change is logged.
 */
final class Channels
{
    public const TABLE = 'cattophp_marketing_channels';
    public const KEY = '/^[a-z0-9][a-z0-9-]{0,39}$/';
    public const PREFIX = '/^[a-z]{1,3}$/';

    public function __construct(
        private readonly Connection $connection,
        private readonly ?ActivityLog $activity = null,
        private readonly ?IconLibrary $icons = null,
    ) {
    }

    /** @return list<Channel> every channel in its order, the retired too unless only active ones are asked for */
    public function all(bool $activeOnly = false): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.*, (SELECT COUNT(*) FROM ' . Publications::TABLE . ' p WHERE p.channel_key = c.key) AS publications FROM ' . self::TABLE . ' c'
            . ($activeOnly ? ' WHERE c.active' : '') . ' ORDER BY c.position, c.label'
        );

        return array_map(self::channel(...), $rows);
    }

    public function get(string $key): ?Channel
    {
        $row = $this->connection->fetchAssociative('SELECT c.*, (SELECT COUNT(*) FROM ' . Publications::TABLE . ' p WHERE p.channel_key = c.key) AS publications FROM ' . self::TABLE . ' c WHERE c.key = :key', ['key' => $key]);

        return $row === false ? null : self::channel($row);
    }

    /**
     * A new channel, last in the list. Its key is made from its label when none is typed.
     *
     * @throws MarketingRefused
     */
    public function add(string $label, string $linkPrefix, string $icon, ?Actor $actor = null, string $key = ''): Channel
    {
        $label = Rows::typed($label);
        $key = Rows::typed($key) !== '' ? strtolower(Rows::typed($key)) : Rows::slug($label, 40);
        $errors = $this->check($label, $linkPrefix, $icon, null);
        if (preg_match(self::KEY, $key) !== 1) {
            $errors['key'] = 'A channel\'s key is small letters, digits and hyphens, up to 40, such as radio or local-paper.';
        } elseif ($this->get($key) !== null) {
            $errors['key'] = 'There is already a channel with the key ' . $key . '.';
        }
        if ($errors !== []) {
            throw new MarketingRefused($errors);
        }
        $position = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(position), 0) + 10 FROM ' . self::TABLE);
        $this->connection->insert(self::TABLE, ['key' => $key, 'label' => $label, 'link_prefix' => strtolower(trim($linkPrefix)), 'icon' => trim($icon), 'position' => $position]);
        $this->activity?->record('marketing.channel_added', ['channel' => $key, 'label' => $label], 'marketing_channel', $key, $actor);

        return $this->get($key) ?? throw MarketingRefused::because('The channel could not be read back.');
    }

    /**
     * A channel's label, prefix and icon changed (its key stays).
     *
     * @throws MarketingRefused
     */
    public function change(string $key, string $label, string $linkPrefix, string $icon, ?Actor $actor = null): Channel
    {
        $channel = $this->get($key) ?? throw MarketingRefused::because('There is no such channel.');
        $label = Rows::typed($label);
        $errors = $this->check($label, $linkPrefix, $icon, $key);
        if ($errors !== []) {
            throw new MarketingRefused($errors);
        }
        $this->connection->update(self::TABLE, ['label' => $label, 'link_prefix' => strtolower(trim($linkPrefix)), 'icon' => trim($icon)], ['key' => $key]);
        $this->activity?->record('marketing.channel_changed', ['channel' => $key, 'label' => $label, 'label_before' => $channel->label], 'marketing_channel', $key, $actor);

        return new Channel($key, $label, strtolower(trim($linkPrefix)), trim($icon), $channel->position, $channel->active, $channel->publications);
    }

    /**
     * The channels put in the order given; any not named keep their order after them.
     *
     * @param list<string> $keys
     */
    public function reorder(array $keys, ?Actor $actor = null): void
    {
        $known = array_map(static fn(Channel $channel): string => $channel->key, $this->all());
        $order = array_values(array_unique([...array_values(array_intersect($keys, $known)), ...$known]));
        $this->connection->transactional(function (Connection $connection) use ($order): void {
            foreach ($order as $index => $key) {
                $connection->update(self::TABLE, ['position' => ($index + 1) * 10], ['key' => $key]);
            }
        });
        $this->activity?->record('marketing.channels_reordered', ['channels' => $order], 'marketing_channel', null, $actor);
    }

    /** Moves a channel one place up (-1) or down (1). */
    public function move(string $key, int $by, ?Actor $actor = null): void
    {
        $keys = array_map(static fn(Channel $channel): string => $channel->key, $this->all());
        $at = array_search($key, $keys, true);
        if ($at === false) {
            throw MarketingRefused::because('There is no such channel.');
        }
        $to = max(0, min(count($keys) - 1, $at + ($by < 0 ? -1 : 1)));
        [$keys[$at], $keys[$to]] = [$keys[$to], $keys[$at]];
        $this->reorder(array_values($keys), $actor);
    }

    /** Takes a channel out of the choices for new publications; its publications keep it. */
    public function retire(string $key, ?Actor $actor = null): void
    {
        $this->get($key) ?? throw MarketingRefused::because('There is no such channel.');
        $this->connection->update(self::TABLE, ['active' => 'false'], ['key' => $key]);
        $this->activity?->record('marketing.channel_retired', ['channel' => $key], 'marketing_channel', $key, $actor);
    }

    /** Offers a retired channel again. */
    public function restore(string $key, ?Actor $actor = null): void
    {
        $this->get($key) ?? throw MarketingRefused::because('There is no such channel.');
        $this->connection->update(self::TABLE, ['active' => 'true'], ['key' => $key]);
        $this->activity?->record('marketing.channel_restored', ['channel' => $key], 'marketing_channel', $key, $actor);
    }

    /**
     * Deletes a channel no publication was ever made on.
     *
     * @throws MarketingRefused when it is in use: it is retired instead
     */
    public function delete(string $key, ?Actor $actor = null): void
    {
        $channel = $this->get($key) ?? throw MarketingRefused::because('There is no such channel.');
        if ($channel->inUse()) {
            throw MarketingRefused::because($channel->label . ' has publications, so it is kept: retire it instead.');
        }
        $this->connection->delete(self::TABLE, ['key' => $key]);
        $this->activity?->record('marketing.channel_deleted', ['channel' => $key, 'label' => $channel->label], 'marketing_channel', $key, $actor);
    }

    /** @return array<string,string> why each typed value is refused, by its field */
    private function check(string $label, string $linkPrefix, string $icon, ?string $key): array
    {
        $errors = [];
        if ($label === '' || mb_strlen($label) > 60) {
            $errors['label'] = 'Type the channel\'s name, up to 60 characters.';
        }
        $prefix = strtolower(trim($linkPrefix));
        if (preg_match(self::PREFIX, $prefix) !== 1) {
            $errors['link_prefix'] = 'The prefix is 1 to 3 small letters, such as fb: its publications are suggested as fb1, fb2 and so on.';
        } elseif ($this->connection->fetchOne('SELECT key FROM ' . self::TABLE . ' WHERE link_prefix = :prefix AND key <> :key', ['prefix' => $prefix, 'key' => $key ?? '']) !== false) {
            $errors['link_prefix'] = 'Another channel has the prefix ' . $prefix . '.';
        }
        $icon = trim($icon);
        if ($icon === '' || preg_match('/^[a-z0-9-]{1,64}$/', $icon) !== 1 || ($this->icons !== null && !$this->icons->has($icon))) {
            $errors['icon'] = 'Choose an icon from the library.';
        }

        return $errors;
    }

    /** @param array<string,mixed> $row */
    private static function channel(array $row): Channel
    {
        return new Channel(Rows::text($row['key']), Rows::text($row['label']), Rows::text($row['link_prefix']), Rows::text($row['icon']), Rows::int($row['position']),
            Rows::bool($row['active']), Rows::int($row['publications'] ?? 0));
    }
}
