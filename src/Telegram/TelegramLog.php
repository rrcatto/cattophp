<?php

declare(strict_types=1);

namespace CattoPHP\Telegram;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Every message the site sends by Telegram (cattophp_telegram_messages, the Telegram log). The owner's own (no chat) are
 * kept in the transaction of what they are about and sent every minute (OwnerMessageSender), once each, by reference. A
 * person's go at once (TelegramMessenger) and are kept as they went, with the chat and the account they went to at that
 * moment; a sign-in message's body here never holds its code or link. A person is their account id in text.
 */
class TelegramLog
{
    /** One reader of the bot's updates at a time, across the site's processes. */
    private const INBOX_LOCK = 7384120461;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Whatever keeps a message, unable to harm what it is about: inside a transaction a savepoint holds any fault back,
     * because one failed statement would otherwise end the registration or the order around it.
     */
    public function guarded(callable $keep): void
    {
        $inside = $this->connection->isTransactionActive();
        try {
            if ($inside) $this->connection->executeStatement('SAVEPOINT telegram_message');
            $keep();
            if ($inside) $this->connection->executeStatement('RELEASE SAVEPOINT telegram_message');
        } catch (Throwable $fault) {
            error_log('A message to the owner could not be kept: ' . $fault::class);
            if ($inside) {
                try {
                    $this->connection->executeStatement('ROLLBACK TO SAVEPOINT telegram_message');
                } catch (Throwable) {
                }
            }
        }
    }

    /** Keeps a message to the owner to send, unless one with this reference was kept before. */
    public function record(string $reference, string $kind, string $body): void
    {
        $this->connection->executeStatement(
            'INSERT INTO cattophp_telegram_messages (reference, kind, body) VALUES (:reference, :kind, :body) ON CONFLICT (reference) DO NOTHING',
            ['reference' => $reference, 'kind' => $kind, 'body' => $body]
        );
    }

    /** @return list<array{id:int,reference:string,kind:string,body:string,tries:int}> the oldest of the owner's own messages still to send */
    public function waiting(int $limit): array
    {
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'reference' => (string) $row['reference'], 'kind' => (string) $row['kind'], 'body' => (string) $row['body'], 'tries' => (int) $row['tries']],
            $this->connection->fetchAllAssociative("SELECT id, reference, kind, body, tries FROM cattophp_telegram_messages WHERE sent_at IS NULL AND chat_id IS NULL AND kind <> 'test' ORDER BY created_at, id LIMIT " . max(1, $limit)));
    }

    /** One of the owner's own gone, to the account named when it went. */
    public function markSent(int $id, ?string $username = null): void
    {
        $this->connection->executeStatement('UPDATE cattophp_telegram_messages SET sent_at = NOW(), tries = tries + 1, last_tried_at = NOW(), last_error = NULL, to_username = COALESCE(:username, to_username) WHERE id = :id',
            ['id' => $id, 'username' => self::username($username)]);
    }

    public function markFailed(int $id, string $error, ?string $username = null): void
    {
        $this->connection->executeStatement('UPDATE cattophp_telegram_messages SET tries = tries + 1, last_tried_at = NOW(), last_error = :error, to_username = COALESCE(:username, to_username) WHERE id = :id',
            ['id' => $id, 'error' => mb_substr($error, 0, 500), 'username' => self::username($username)]);
    }

    public function deleteWaitingBefore(DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM cattophp_telegram_messages WHERE sent_at IS NULL AND created_at < :before', ['before' => $before->format(DATE_ATOM)]);
    }

    public function deleteSentBefore(DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM cattophp_telegram_messages WHERE sent_at IS NOT NULL AND sent_at < :before', ['before' => $before->format(DATE_ATOM)]);
    }

    /** @return array{waiting:int,last_sent_at:?string,last_error:?string} how the owner's own messages are going */
    public function status(): array
    {
        $failing = $this->connection->fetchOne("SELECT last_error FROM cattophp_telegram_messages WHERE sent_at IS NULL AND last_error IS NOT NULL AND chat_id IS NULL AND kind <> 'test' ORDER BY last_tried_at DESC LIMIT 1");
        $sent = $this->connection->fetchOne('SELECT MAX(sent_at) FROM cattophp_telegram_messages WHERE chat_id IS NULL');

        return ['waiting' => (int) $this->connection->fetchOne("SELECT COUNT(*) FROM cattophp_telegram_messages WHERE sent_at IS NULL AND chat_id IS NULL AND kind <> 'test'"),
            'last_sent_at' => is_string($sent) ? $sent : null, 'last_error' => is_string($failing) ? $failing : null];
    }

    /** The owner's test message, kept for the log once it has gone or failed. It goes at once and is never sent again. */
    public function logTest(string $reference, string $body, ?string $error, ?string $username = null): void
    {
        $this->connection->executeStatement(
            "INSERT INTO cattophp_telegram_messages (reference, kind, body, sent_at, tries, last_tried_at, last_error, to_username)
             VALUES (:reference, 'test', :body, CASE WHEN CAST(:error AS TEXT) IS NULL THEN NOW() END, 1, NOW(), :error, :username)",
            ['reference' => $reference, 'body' => $body, 'error' => $error === null ? null : mb_substr($error, 0, 500), 'username' => self::username($username)]
        );
    }

    /**
     * A message to a person (a sign-in message, a connection, the bot's answer, their test), kept once it has gone or failed,
     * with the chat and the account it went to at that moment.
     */
    public function logSent(string $reference, string $kind, string $body, string $chatId, ?string $account, ?string $username, ?string $error): void
    {
        $this->connection->executeStatement(
            'INSERT INTO cattophp_telegram_messages (reference, kind, body, chat_id, to_account, to_username, sent_at, tries, last_tried_at, last_error)
             VALUES (:reference, :kind, :body, :chat, :account, :username, CASE WHEN CAST(:error AS TEXT) IS NULL THEN NOW() END, 1, NOW(), :error)',
            ['reference' => $reference, 'kind' => $kind, 'body' => $body, 'chat' => $chatId, 'account' => $account, 'username' => self::username($username),
                'error' => $error === null ? null : mb_substr($error, 0, 500)]
        );
    }

    /** One reader of the bot's updates at a time, across the site's processes: whether this one may read. */
    public function takeInboxTurn(): bool
    {
        return in_array($this->connection->fetchOne('SELECT pg_try_advisory_lock(' . self::INBOX_LOCK . ')'), [true, 1, '1', 't', 'true'], true);
    }

    public function endInboxTurn(): void
    {
        $this->connection->fetchOne('SELECT pg_advisory_unlock(' . self::INBOX_LOCK . ')');
    }

    /** A Telegram username as it is kept: without its @, or null. */
    private static function username(?string $username): ?string
    {
        $username = ltrim(trim((string) $username), '@');

        return $username === '' ? null : $username;
    }

    /**
     * An account being deleted: the messages sent to it stay in the Telegram log, to nobody named now: neither its account,
     * nor its Telegram username, nor its chat. Those sent to its account, to its chat ($chatId, the Telegram account it
     * connected) or to its username, whatever its case, as the owner's own messages are once they have gone. A person's
     * chat is left empty, never null, which is the owner's own still to send. Returns how many.
     */
    public function forget(string $account, ?string $chatId = null, ?string $username = null): int
    {
        return (int) $this->connection->executeStatement(
            "UPDATE cattophp_telegram_messages SET to_account = NULL, to_username = NULL, chat_id = CASE WHEN chat_id IS NULL THEN NULL ELSE '' END
              WHERE to_account = :account OR (:chat <> '' AND chat_id = :chat) OR (:username <> '' AND LOWER(to_username) = LOWER(:username))",
            ['account' => $account, 'chat' => trim((string) $chatId), 'username' => self::username($username) ?? '']
        );
    }
}
