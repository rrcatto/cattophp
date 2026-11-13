<?php

declare(strict_types=1);

namespace CattoPHP\Mailing\CattoMail;

use CattoPHP\Account\AccountRecords;
use Doctrine\DBAL\Connection;

/**
 * The events catto-mail reports to the webhook, each kept once by its id (a repeat is answered and changes nothing), then
 * worked through by the project's handlers (CattoMailEventHandler), oldest first, each event's outcome kept beside it in
 * cattophp_cattomail_events. An event can be taken again (its outcome cleared) from the events' page.
 */
final class CattoMailEvents
{
    public const TABLE = 'cattophp_cattomail_events';

    /** @var array<string,list<CattoMailEventHandler>> */
    private array $byType = [];

    /** @param iterable<CattoMailEventHandler> $handlers */
    public function __construct(private readonly Connection $connection, iterable $handlers = [])
    {
        foreach ($handlers as $handler) {
            foreach ($handler->types() as $type) $this->byType[$type][] = $handler;
        }
    }

    /** Keeps an event, once. False when it was already kept. */
    public function receive(string $eventId, string $type, string $body): bool
    {
        return $this->connection->executeStatement(
            'INSERT INTO ' . self::TABLE . ' (event_id, event_type, payload, received_at) VALUES (:id, :type, CAST(:payload AS JSONB), NOW()) ON CONFLICT (event_id) DO NOTHING',
            ['id' => mb_substr($eventId, 0, 100), 'type' => mb_substr($type, 0, 60), 'payload' => $body]
        ) > 0;
    }

    /** Works through the events not yet processed, in one transaction. Returns how many were processed. */
    public function process(int $limit = 100): int
    {
        return $this->connection->transactional(function (Connection $connection) use ($limit): int {
            $done = 0;
            foreach ($connection->fetchAllAssociative('SELECT id, event_type, payload FROM ' . self::TABLE . ' WHERE processed_at IS NULL ORDER BY id LIMIT :limit FOR UPDATE SKIP LOCKED', ['limit' => $limit]) as $event) {
                $type = (string) $event['event_type'];
                $payload = json_decode((string) $event['payload'], true);
                /** @var array<string,mixed> $payload */
                $payload = is_array($payload) ? $payload : [];
                $outcomes = [];
                foreach ($this->byType[$type] ?? [] as $handler) $outcomes[] = $handler->handle($type, $payload);
                $connection->executeStatement('UPDATE ' . self::TABLE . ' SET processed_at = NOW(), outcome = :outcome WHERE id = :id',
                    ['id' => $event['id'], 'outcome' => mb_substr($outcomes === [] ? 'Kept; nothing to do.' : implode(' ', $outcomes), 0, 500)]);
                $done++;
            }

            return $done;
        });
    }

    /**
     * The events, newest first, of one type or all: each with the copy's reference and the send job's id when it names
     * them.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(string $type = '', int $limit = 100): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT id, event_id, event_type, received_at, processed_at, outcome, payload->'data'->'message'->>'external_recipient_reference' AS reference, payload->'data'->>'id' AS job_id
               FROM " . self::TABLE . " WHERE (:type = '' OR event_type = :type) ORDER BY received_at DESC, id DESC LIMIT :limit",
            ['type' => $type, 'limit' => $limit]
        );
    }

    /** An event taken again on the next run. False when there is none of that id. */
    public function retry(int $id): bool
    {
        return $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET processed_at = NULL, outcome = NULL WHERE id = :id', ['id' => $id]) > 0;
    }

    /**
     * A person whose account is gone: every event that names their address keeps it no more, in what catto-mail sent or
     * the outcome kept beside it, whatever its case; it says AccountRecords::FORGOTTEN_EMAIL instead. Returns how many.
     */
    public function forget(string $email): int
    {
        $email = trim($email);
        if ($email === '') {
            return 0;
        }
        // The address standing on its own, never inside a longer one (anna@example.com is not in hanna@example.com).
        $pattern = '(?<![[:alnum:]_.%+@-])' . preg_quote($email) . '(?![[:alnum:]_@]|[.-][[:alnum:]])';

        return (int) $this->connection->executeStatement(
            'UPDATE ' . self::TABLE . " SET payload = CAST(regexp_replace(CAST(payload AS text), :pattern, :forgotten, 'gi') AS jsonb),
                    outcome = regexp_replace(outcome, :pattern, :forgotten, 'gi')
              WHERE CAST(payload AS text) ~* :pattern OR outcome ~* :pattern",
            ['pattern' => $pattern, 'forgotten' => AccountRecords::FORGOTTEN_EMAIL]
        );
    }

    /** How many are waiting to be processed. */
    public function waiting(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE processed_at IS NULL');
    }
}
