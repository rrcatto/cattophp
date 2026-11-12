<?php

declare(strict_types=1);

namespace CattoPHP\Inbox;

use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * The Inbox's records: conversations (a message from the contact form, cattophp_inbox_conversations) and their items
 * (cattophp_inbox_items) by folder, with what the menu counts; keeping a message with its reference and marking it
 * emailed, acknowledged and answered; moving to Trash, restoring, deleting for good and emptying; the spam check's facts
 * and the owner's verdict; the word filter's counts and what each message taught it; blocked senders; replies and drafts;
 * and where the mailbox stood. A person is their account in text. A project's own pages read the lists with the people
 * they name.
 */
final class InboxStore
{
    public const FOLDERS = ['inbox', 'spam', 'trash'];

    /** @param string $referencePrefix what a conversation's reference starts with, its number after it: MSG-000043 */
    public function __construct(private readonly Connection $connection, private readonly string $referencePrefix = 'MSG-')
    {
    }

    public function referencePrefix(): string
    {
        return $this->referencePrefix;
    }

    /**
     * Runs a piece of work in one transaction (inside a project's own, when one is open).
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        return $this->connection->transactional(static fn(): mixed => $work());
    }

    /**
     * Keeps a message and gives it its reference, the prefix and its number.
     *
     * @param array{reason:string,sender_name:string,sender_email:string,account?:?string,account_reference?:?string,details?:array<string,string>,message:string} $message
     * @return array{id:int,reference:string}
     */
    public function createConversation(array $message): array
    {
        $row = $this->connection->fetchAssociative(
            "WITH next AS (SELECT nextval(pg_get_serial_sequence('cattophp_inbox_conversations', 'id')) AS id)
             INSERT INTO cattophp_inbox_conversations (id, reference, reason, sender_name, sender_email, account, account_reference, details, message)
             SELECT next.id, :prefix || lpad(next.id::text, 6, '0'), :reason, :sender_name, :sender_email, :account, :account_reference, CAST(:details AS jsonb), :message
               FROM next
             RETURNING id, reference",
            ['prefix' => $this->referencePrefix, 'reason' => $message['reason'], 'sender_name' => $message['sender_name'], 'sender_email' => $message['sender_email'],
                'account' => $message['account'] ?? null, 'account_reference' => $message['account_reference'] ?? null,
                'details' => json_encode((object) ($message['details'] ?? []), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'message' => $message['message']],
        );
        if ($row === false) {
            throw new RuntimeException('The message could not be kept.');
        }

        return ['id' => (int) $row['id'], 'reference' => (string) $row['reference']];
    }

    public function markEmailed(int $id): void
    {
        $this->connection->executeStatement('UPDATE cattophp_inbox_conversations SET emailed_at = NOW() WHERE id = :id AND emailed_at IS NULL', ['id' => $id]);
    }

    public function markAcknowledged(int $id): void
    {
        $this->connection->executeStatement('UPDATE cattophp_inbox_conversations SET acknowledged_at = NOW() WHERE id = :id AND acknowledged_at IS NULL', ['id' => $id]);
    }

    /** Marks a message answered once. False when it already was. */
    public function markAnswered(int $id, string $actor): bool
    {
        return $this->connection->executeStatement(
            'UPDATE cattophp_inbox_conversations SET answered_at = NOW(), answered_by = :actor WHERE id = :id AND answered_at IS NULL',
            ['id' => $id, 'actor' => $actor],
        ) === 1;
    }

    /** A message marked answered by mistake, open again. False when it was not answered. */
    public function markUnanswered(int $id): bool
    {
        return $this->connection->executeStatement(
            'UPDATE cattophp_inbox_conversations SET answered_at = NULL, answered_by = NULL WHERE id = :id AND answered_at IS NOT NULL',
            ['id' => $id],
        ) === 1;
    }

    /** @return array<string,mixed>|null a conversation's own record, its spam signs as their JSON */
    public function conversation(int $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT *, spam_signals::text AS spam_signals, details::text AS details FROM cattophp_inbox_conversations WHERE id = :id', ['id' => $id]);

        return $row === false ? null : $row;
    }

    /** @return array{new:int,drafts:int,spam:int,trash:int} what the menu says beside each folder */
    public function counts(): array
    {
        $row = $this->connection->fetchAssociative("SELECT
            (SELECT COUNT(*) FROM cattophp_inbox_conversations WHERE folder = 'inbox' AND (read_at IS NULL OR unread_answer))::int AS new,
            (SELECT COUNT(*) FROM cattophp_inbox_items WHERE status = 'draft' AND trashed_at IS NULL)::int AS drafts,
            (SELECT COUNT(*) FROM cattophp_inbox_conversations WHERE folder = 'spam')::int AS spam,
            ((SELECT COUNT(*) FROM cattophp_inbox_conversations WHERE folder = 'trash') + (SELECT COUNT(*) FROM cattophp_inbox_items WHERE trashed_at IS NOT NULL))::int AS trash") ?: [];

        return ['new' => (int) ($row['new'] ?? 0), 'drafts' => (int) ($row['drafts'] ?? 0), 'spam' => (int) ($row['spam'] ?? 0), 'trash' => (int) ($row['trash'] ?? 0)];
    }

    public function idByReference(string $reference): ?int
    {
        $id = $this->connection->fetchOne('SELECT id FROM cattophp_inbox_conversations WHERE reference = :reference', ['reference' => $reference]);

        return $id === false ? null : (int) $id;
    }

    public function markRead(int $id): void
    {
        $this->connection->executeStatement('UPDATE cattophp_inbox_conversations SET read_at = COALESCE(read_at, NOW()), unread_answer = FALSE WHERE id = :id', ['id' => $id]);
    }

    /** @param list<int> $ids */
    public function moveToTrash(array $ids): int
    {
        return $ids === [] ? 0 : (int) $this->connection->executeStatement(
            "UPDATE cattophp_inbox_conversations SET folder_before_trash = folder, folder = 'trash', trashed_at = NOW() WHERE id = ANY(CAST(:ids AS bigint[])) AND folder <> 'trash'",
            ['ids' => self::array($ids)],
        );
    }

    /** @param list<int> $ids */
    public function restore(array $ids): int
    {
        return $ids === [] ? 0 : (int) $this->connection->executeStatement(
            "UPDATE cattophp_inbox_conversations SET folder = COALESCE(folder_before_trash, 'inbox'), folder_before_trash = NULL, trashed_at = NULL WHERE id = ANY(CAST(:ids AS bigint[])) AND folder = 'trash'",
            ['ids' => self::array($ids)],
        );
    }

    /**
     * Deletes conversations for good, with their items: only from Trash or Spam.
     *
     * @param list<int> $ids
     */
    public function deleteForever(array $ids): int
    {
        return $ids === [] ? 0 : (int) $this->connection->executeStatement("DELETE FROM cattophp_inbox_conversations WHERE id = ANY(CAST(:ids AS bigint[])) AND folder IN ('trash','spam')", ['ids' => self::array($ids)]);
    }

    /** Empties a folder (Trash or Spam), all of it or what has been there since before a time. */
    public function emptyFolder(string $folder, ?string $before = null): int
    {
        if (!in_array($folder, ['trash', 'spam'], true)) {
            return 0;
        }
        $column = $folder === 'trash' ? 'trashed_at' : 'last_activity_at';
        $drafts = $folder !== 'trash' ? 0 : ($before === null
            ? (int) $this->connection->executeStatement('DELETE FROM cattophp_inbox_items WHERE trashed_at IS NOT NULL')
            : (int) $this->connection->executeStatement('DELETE FROM cattophp_inbox_items WHERE trashed_at < CAST(:before AS timestamptz)', ['before' => $before]));

        return $drafts + ($before === null
            ? (int) $this->connection->executeStatement('DELETE FROM cattophp_inbox_conversations WHERE folder = :folder', ['folder' => $folder])
            : (int) $this->connection->executeStatement('DELETE FROM cattophp_inbox_conversations WHERE folder = :folder AND ' . $column . ' < CAST(:before AS timestamptz)', ['folder' => $folder, 'before' => $before]));
    }

    /** Conversations kept as long as the settings say, then deleted by the daily clean-up. */
    public function deleteOlderThan(string $before): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM cattophp_inbox_conversations WHERE last_activity_at < CAST(:before AS timestamptz)', ['before' => $before]);
    }

    /** @param list<int> $ids */
    public function setFolder(array $ids, string $folder, ?string $verdict): int
    {
        return $ids === [] ? 0 : (int) $this->connection->executeStatement(
            'UPDATE cattophp_inbox_conversations SET folder = :folder, folder_before_trash = NULL, trashed_at = NULL, spam_verdict = :verdict WHERE id = ANY(CAST(:ids AS bigint[]))',
            ['folder' => $folder, 'verdict' => $verdict, 'ids' => self::array($ids)],
        );
    }

    /**
     * What the spam check found when the message came in, and how it was sent.
     *
     * @param list<array{key:string,points:int,words:string}> $signs
     * @param array{ip?:string,agent?:string,seconds?:?int,country?:string} $sender
     */
    public function recordCheck(int $id, int $score, array $signs, string $folder, array $sender): void
    {
        $this->connection->executeStatement(
            'UPDATE cattophp_inbox_conversations SET spam_score = :score, spam_signals = CAST(:signs AS jsonb), folder = :folder, sender_ip = :ip, sender_agent = :agent, seconds_on_page = :seconds, sender_country = :country WHERE id = :id',
            ['id' => $id, 'score' => max(0, min(100, $score)), 'signs' => json_encode($signs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'folder' => $folder,
                'ip' => ($sender['ip'] ?? '') ?: null, 'agent' => mb_substr($sender['agent'] ?? '', 0, 500) ?: null, 'seconds' => $sender['seconds'] ?? null, 'country' => ($sender['country'] ?? '') ?: null],
        );
    }

    /** Messages from one address since a time. */
    public function fromAddressSince(string $ip, string $since): int
    {
        return $ip === '' ? 0 : (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_inbox_conversations WHERE sender_ip = :ip AND created_at >= CAST(:since AS timestamptz)', ['ip' => $ip, 'since' => $since]);
    }

    /** Whether the same words were sent before, since a time (spam is sent again and again). */
    public function sameWordsSince(string $message, string $since, int $exceptId): bool
    {
        return $this->connection->fetchOne(
            "SELECT 1 FROM cattophp_inbox_conversations WHERE id <> :id AND created_at >= CAST(:since AS timestamptz) AND lower(regexp_replace(message, '\\s+', ' ', 'g')) = lower(regexp_replace(:message, '\\s+', ' ', 'g')) LIMIT 1",
            ['id' => $exceptId, 'since' => $since, 'message' => $message],
        ) !== false;
    }

    /** Whether an earlier message from this address was found genuine: answered, or marked not spam. */
    public function knownSender(string $email, int $exceptId): bool
    {
        return $this->connection->fetchOne(
            "SELECT 1 FROM cattophp_inbox_conversations WHERE id <> :id AND lower(sender_email) = lower(:email) AND (spam_verdict = 'ham' OR answered_at IS NOT NULL) LIMIT 1",
            ['id' => $exceptId, 'email' => $email],
        ) !== false;
    }

    /**
     * @param list<string> $words
     * @return array<string,array{0:int,1:int}> each known word's [spam, genuine] counts
     */
    public function wordCounts(array $words): array
    {
        if ($words === []) {
            return [];
        }
        $counts = [];
        foreach ($this->connection->fetchAllAssociative('SELECT token, spam_count, ham_count FROM cattophp_inbox_spam_tokens WHERE token = ANY(CAST(:words AS text[]))', ['words' => self::textArray($words)]) as $row) {
            $counts[(string) $row['token']] = [(int) $row['spam_count'], (int) $row['ham_count']];
        }

        return $counts;
    }

    /** @return array{spam:int,ham:int} how many messages of each kind the filter has learnt from */
    public function trained(): array
    {
        $row = $this->connection->fetchAssociative("SELECT COUNT(*) FILTER (WHERE verdict = 'spam')::int AS spam, COUNT(*) FILTER (WHERE verdict = 'ham')::int AS ham FROM cattophp_inbox_spam_training") ?: [];

        return ['spam' => (int) ($row['spam'] ?? 0), 'ham' => (int) ($row['ham'] ?? 0)];
    }

    /**
     * Teaches the filter one message: its words counted under the verdict, and what it taught before under another verdict
     * taken back, so changing one's mind leaves the counts true.
     *
     * @param list<string> $words
     */
    public function train(int $id, string $verdict, array $words, ?string $actor): void
    {
        $before = $this->connection->fetchAssociative('SELECT verdict, tokens::text AS tokens FROM cattophp_inbox_spam_training WHERE conversation_id = :id', ['id' => $id]);
        if ($before !== false && $before['verdict'] === $verdict) {
            return;
        }
        if ($before !== false) {
            $column = $before['verdict'] === 'spam' ? 'spam_count' : 'ham_count';
            $old = json_decode((string) $before['tokens'], true);
            if (is_array($old) && $old !== []) {
                $this->connection->executeStatement("UPDATE cattophp_inbox_spam_tokens SET {$column} = GREATEST(0, {$column} - 1), updated_at = NOW() WHERE token = ANY(CAST(:words AS text[]))",
                    ['words' => self::textArray(array_values(array_map('strval', $old)))]);
            }
        }
        $column = $verdict === 'spam' ? 'spam_count' : 'ham_count';
        foreach ($words as $word) {
            $this->connection->executeStatement("INSERT INTO cattophp_inbox_spam_tokens(token, {$column}) VALUES (:word, 1) ON CONFLICT (token) DO UPDATE SET {$column} = cattophp_inbox_spam_tokens.{$column} + 1, updated_at = NOW()", ['word' => $word]);
        }
        $this->connection->executeStatement(
            'INSERT INTO cattophp_inbox_spam_training(conversation_id, verdict, tokens, trained_by, trained_at) VALUES (:id, :verdict, CAST(:tokens AS jsonb), :actor, NOW())
             ON CONFLICT (conversation_id) DO UPDATE SET verdict = EXCLUDED.verdict, tokens = EXCLUDED.tokens, trained_by = EXCLUDED.trained_by, trained_at = NOW()',
            ['id' => $id, 'verdict' => $verdict, 'tokens' => json_encode($words, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'actor' => $actor],
        );
    }

    /** Forgets everything the filter learnt. */
    public function forget(): void
    {
        $this->connection->executeStatement('DELETE FROM cattophp_inbox_spam_training');
        $this->connection->executeStatement('DELETE FROM cattophp_inbox_spam_tokens');
    }

    public function block(string $value, string $kind, ?string $actor): void
    {
        $this->connection->executeStatement('INSERT INTO cattophp_inbox_blocked_senders(value, kind, created_by) VALUES (:value, :kind, :actor) ON CONFLICT (value) DO NOTHING',
            ['value' => mb_strtolower($value), 'kind' => $kind, 'actor' => $actor]);
    }

    public function isBlocked(string $email): bool
    {
        $email = mb_strtolower(trim($email));
        $domain = (string) substr((string) strrchr($email, '@'), 1);

        return $this->connection->fetchOne("SELECT 1 FROM cattophp_inbox_blocked_senders WHERE value = :email OR (kind = 'domain' AND value = :domain) LIMIT 1", ['email' => $email, 'domain' => $domain]) !== false;
    }

    /**
     * A new item of a conversation, received or sent (or a draft): its id.
     *
     * @param array<string,mixed> $item
     */
    public function addItem(int $conversationId, array $item): int
    {
        return (int) $this->connection->fetchOne(
            'INSERT INTO cattophp_inbox_items(conversation_id,direction,kind,status,format,subject,from_name,from_email,to_email,body_text,body_html,attachments,message_id,in_reply_to,mark_answered,written_by,error,sent_at,received_at)
             VALUES (:conversation,:direction,:kind,:status,:format,:subject,:from_name,:from_email,:to_email,:body_text,:body_html,CAST(:attachments AS jsonb),:message_id,:in_reply_to,:mark_answered,:written_by,:error,:sent_at,:received_at) RETURNING id',
            ['conversation' => $conversationId, 'direction' => $item['direction'], 'kind' => $item['kind'], 'status' => $item['status'], 'format' => $item['format'] ?? 'plain',
                'subject' => self::text($item['subject'] ?? ''), 'from_name' => self::text($item['from_name'] ?? ''), 'from_email' => self::text($item['from_email'] ?? ''), 'to_email' => self::text($item['to_email'] ?? ''),
                'body_text' => self::text($item['body_text'] ?? ''), 'body_html' => $item['body_html'] ?? null, 'attachments' => json_encode($item['attachments'] ?? [], JSON_THROW_ON_ERROR),
                'message_id' => $item['message_id'] ?? null, 'in_reply_to' => $item['in_reply_to'] ?? null, 'mark_answered' => ($item['mark_answered'] ?? true) ? 'true' : 'false',
                'written_by' => isset($item['written_by']) ? self::text($item['written_by']) : null, 'error' => $item['error'] ?? null, 'sent_at' => $item['sent_at'] ?? null, 'received_at' => $item['received_at'] ?? null],
        );
    }

    /** @return array<string,mixed>|null */
    public function item(int $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT *, attachments::text AS attachments FROM cattophp_inbox_items WHERE id = :id', ['id' => $id]);

        return $row === false ? null : $row;
    }

    /**
     * A conversation's items as kept (no draft, nothing trashed), oldest first.
     *
     * @return list<array<string,mixed>>
     */
    public function itemsOf(int $conversationId): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT *, attachments::text AS attachments FROM cattophp_inbox_items WHERE conversation_id = :id AND trashed_at IS NULL AND status <> 'draft' ORDER BY COALESCE(sent_at, received_at, created_at), id",
            ['id' => $conversationId],
        );
    }

    /** @param array<string,mixed> $changes */
    public function updateItem(int $id, array $changes): void
    {
        $allowed = ['status', 'format', 'subject', 'body_text', 'body_html', 'attachments', 'message_id', 'in_reply_to', 'mark_answered', 'error', 'sent_at', 'to_email', 'from_name', 'from_email'];
        $set = [];
        $params = ['id' => $id];
        foreach ($changes as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                continue;
            }
            $set[] = $column === 'attachments' ? 'attachments = CAST(:attachments AS jsonb)' : $column . ' = :' . $column;
            $params[$column] = match ($column) {
                'attachments' => json_encode($value, JSON_THROW_ON_ERROR),
                'mark_answered' => $value ? 'true' : 'false',
                default => $value,
            };
        }
        if ($set === []) {
            return;
        }
        $this->connection->executeStatement('UPDATE cattophp_inbox_items SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE id = :id', $params);
    }

    /** A draft put in the Trash (Discard, or Delete in Drafts). */
    public function trashDraft(int $id): bool
    {
        return $this->connection->executeStatement("UPDATE cattophp_inbox_items SET trashed_at = NOW() WHERE id = :id AND status = 'draft' AND trashed_at IS NULL", ['id' => $id]) === 1;
    }

    /** @param list<int> $ids */
    public function restoreDrafts(array $ids): int
    {
        return $ids === [] ? 0 : (int) $this->connection->executeStatement('UPDATE cattophp_inbox_items SET trashed_at = NULL WHERE id = ANY(CAST(:ids AS bigint[])) AND trashed_at IS NOT NULL', ['ids' => self::array($ids)]);
    }

    /** @param list<int> $ids */
    public function deleteDraftsForever(array $ids): int
    {
        return $ids === [] ? 0 : (int) $this->connection->executeStatement('DELETE FROM cattophp_inbox_items WHERE id = ANY(CAST(:ids AS bigint[])) AND trashed_at IS NOT NULL', ['ids' => self::array($ids)]);
    }

    /**
     * The conversation's draft reply being written, if there is one.
     *
     * @return array<string,mixed>|null
     */
    public function draftOf(int $conversationId): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT *, attachments::text AS attachments FROM cattophp_inbox_items WHERE conversation_id = :id AND status = 'draft' AND trashed_at IS NULL ORDER BY updated_at DESC, id DESC LIMIT 1",
            ['id' => $conversationId],
        );

        return $row === false ? null : $row;
    }

    /** A conversation was answered or written to: it comes to the top. */
    public function touched(int $conversationId, bool $unreadAnswer = false): void
    {
        $this->connection->executeStatement(
            'UPDATE cattophp_inbox_conversations SET last_activity_at = NOW(), unread_answer = unread_answer OR CAST(:unread AS boolean) WHERE id = :id',
            ['id' => $conversationId, 'unread' => $unreadAnswer ? 'true' : 'false'],
        );
    }

    /**
     * An answer came in: the conversation comes to the top, says "Answer received", is not answered until replied to, and
     * leaves the Trash for the Inbox. One in Spam stays there.
     */
    public function answerArrived(int $conversationId): void
    {
        $this->connection->executeStatement(
            "UPDATE cattophp_inbox_conversations SET last_activity_at = NOW(), unread_answer = TRUE, answered_at = NULL, answered_by = NULL,
                    folder = CASE WHEN folder = 'trash' THEN COALESCE(folder_before_trash, 'inbox') ELSE folder END,
                    folder_before_trash = CASE WHEN folder = 'trash' THEN NULL ELSE folder_before_trash END,
                    trashed_at = CASE WHEN folder = 'trash' THEN NULL ELSE trashed_at END
              WHERE id = :id",
            ['id' => $conversationId],
        );
    }

    /** The conversation an email answers: by a Message-ID it answers, else the reference in its subject. */
    public function conversationFor(?string $inReplyTo, string $subject): ?int
    {
        $inReplyTo = $inReplyTo !== null ? trim($inReplyTo, " \t<>") : null;
        if ($inReplyTo !== null && $inReplyTo !== '') {
            $id = $this->connection->fetchOne('SELECT conversation_id FROM cattophp_inbox_items WHERE message_id = :id', ['id' => $inReplyTo]);
            if ($id !== false) {
                return (int) $id;
            }
        }
        if (preg_match('/\b(' . preg_quote($this->referencePrefix, '/') . '\d{6,18})\b/i', $subject, $match) === 1) {
            return $this->idByReference(strtoupper($match[1]));
        }

        return null;
    }

    public function hasItemWithMessageId(string $messageId): bool
    {
        return $this->connection->fetchOne('SELECT 1 FROM cattophp_inbox_items WHERE message_id = :id', ['id' => $messageId]) !== false;
    }

    /**
     * The Message-IDs of what came back in a conversation, newest first: a reply answers the newest and names them all.
     *
     * @return list<string>
     */
    public function answerMessageIds(int $conversationId): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn(
            "SELECT message_id FROM cattophp_inbox_items WHERE conversation_id = :id AND direction = 'received' AND message_id IS NOT NULL ORDER BY received_at DESC, id DESC",
            ['id' => $conversationId],
        ));
    }

    /** @return array<string,mixed> where the mailbox stood when it was last read, and when, and what went wrong */
    public function mailboxState(): array
    {
        $state = $this->connection->fetchOne('SELECT state::text FROM cattophp_inbox_mailbox WHERE id = 1');
        $decoded = is_string($state) ? json_decode($state, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $state */
    public function saveMailboxState(array $state): void
    {
        $this->connection->executeStatement(
            'INSERT INTO cattophp_inbox_mailbox (id, state, updated_at) VALUES (1, CAST(:state AS jsonb), NOW()) ON CONFLICT (id) DO UPDATE SET state = EXCLUDED.state, updated_at = NOW()',
            ['state' => json_encode((object) $state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
        );
    }

    public function forgetMailboxState(): void
    {
        $this->connection->executeStatement('DELETE FROM cattophp_inbox_mailbox');
    }

    /** @param list<int> $ids */
    private static function array(array $ids): string
    {
        return '{' . implode(',', array_map('intval', $ids)) . '}';
    }

    /** @param list<string> $words */
    private static function textArray(array $words): string
    {
        return '{' . implode(',', array_map(static fn(string $w): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $w) . '"', $words)) . '}';
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
