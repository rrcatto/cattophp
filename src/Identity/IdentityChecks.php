<?php

declare(strict_types=1);

namespace CattoPHP\Identity;

use Doctrine\DBAL\Connection;

/**
 * The identity checks (cattophp_identity_verifications), the provider's webhook events already handled
 * (cattophp_identity_webhook_events) and when an account was last allowed another try (cattophp_identity_retries). Only
 * the status, the verified name, document type, number and issuing country, date of birth, the provider's session id
 * and when are kept; never an image. An account is its id in text.
 */
final class IdentityChecks
{
    public function __construct(private readonly Connection $connection)
    {
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

    /** @return array<string,mixed> the new check */
    public function create(string $account, string $sessionId): array
    {
        $row = $this->connection->fetchAssociative(
            "INSERT INTO cattophp_identity_verifications (account, session_id, status) VALUES (:account, :session, 'started')
             ON CONFLICT (session_id) DO UPDATE SET updated_at = NOW() RETURNING *",
            ['account' => $account, 'session' => $sessionId],
        );

        return $row === false ? [] : $row;
    }

    /** @return array<string,mixed>|null */
    public function bySession(string $sessionId, bool $lock = false): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM cattophp_identity_verifications WHERE session_id = :session' . ($lock ? ' FOR UPDATE' : ''), ['session' => $sessionId]);

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id, bool $lock = false): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM cattophp_identity_verifications WHERE id = :id' . ($lock ? ' FOR UPDATE' : ''), ['id' => $id]);

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null the account's approved check, which is what makes it verified */
    public function approved(string $account): ?array
    {
        $row = $this->connection->fetchAssociative("SELECT * FROM cattophp_identity_verifications WHERE account = :account AND status = 'approved' LIMIT 1", ['account' => $account]);

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null the account's most recent check */
    public function latest(string $account): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM cattophp_identity_verifications WHERE account = :account ORDER BY started_at DESC, id DESC LIMIT 1', ['account' => $account]);

        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> every check the account made, newest first (at most 50) */
    public function history(string $account): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM cattophp_identity_verifications WHERE account = :account ORDER BY started_at DESC, id DESC LIMIT 50',
            ['account' => $account],
        );
    }

    /** @return list<string> the session id of every check the account made, which an activity log entry may still name */
    public function sessionsOf(string $account): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn('SELECT session_id FROM cattophp_identity_verifications WHERE account = :account ORDER BY id', ['account' => $account]));
    }

    /** Declined checks that count towards the tries: those since an administrator last allowed another try. */
    public function declinedTries(string $account): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM cattophp_identity_verifications iv
               LEFT JOIN cattophp_identity_retries r ON r.account = iv.account
              WHERE iv.account = :account AND iv.status = 'declined'
                AND iv.started_at > COALESCE(r.reset_at, '-infinity'::timestamptz)",
            ['account' => $account],
        );
    }

    /**
     * Records a decision on a check. An approved check keeps the identity it verified; any other keeps none. A revoked
     * check is never changed.
     */
    public function decide(int $id, IdentityDecision $decision): void
    {
        $approved = $decision->status === IdentityDecision::APPROVED;
        $this->connection->executeStatement(
            "UPDATE cattophp_identity_verifications SET status = :status, verified_name = :name, document_type = :type, document_number = :number,
                    issuing_country = :country, date_of_birth = CAST(:birth AS date), decline_reason = :reason,
                    decided_at = CASE WHEN CAST(:final AS boolean) THEN COALESCE(decided_at, NOW()) ELSE decided_at END, updated_at = NOW()
              WHERE id = :id AND status <> 'revoked'",
            [
                'id' => $id, 'status' => $decision->status, 'name' => $approved ? $decision->name : null, 'type' => $approved ? $decision->documentType : null,
                'number' => $approved ? $decision->documentNumber : null, 'country' => $approved ? $decision->issuingCountry : null,
                'birth' => $approved ? $decision->dateOfBirth : null, 'reason' => $decision->declineReason,
                'final' => in_array($decision->status, [...IdentityDecision::FINAL, IdentityDecision::IN_REVIEW], true) ? 'true' : 'false',
            ],
        );
    }

    public function revoke(int $id, string $actor, string $reason): void
    {
        $this->connection->executeStatement(
            "UPDATE cattophp_identity_verifications SET status = 'revoked', revoked_at = NOW(), revoked_by = :actor, revocation_reason = :reason, updated_at = NOW()
              WHERE id = :id AND status = 'approved'",
            ['id' => $id, 'actor' => $actor, 'reason' => $reason],
        );
    }

    /** An administrator allows another try: the declined checks so far stop counting. */
    public function allowAnotherTry(string $account): void
    {
        $this->connection->executeStatement(
            'INSERT INTO cattophp_identity_retries (account, reset_at) VALUES (:account, NOW()) ON CONFLICT (account) DO UPDATE SET reset_at = NOW()',
            ['account' => $account],
        );
    }

    /** Records a webhook event; false when it was already handled. */
    public function rememberEvent(string $eventId): bool
    {
        return $this->connection->executeStatement(
            'INSERT INTO cattophp_identity_webhook_events (event_id) VALUES (CAST(:event AS uuid)) ON CONFLICT (event_id) DO NOTHING',
            ['event' => $eventId],
        ) === 1;
    }

    /** @return list<array<string,mixed>> every check Didit is reviewing, oldest first */
    public function inReview(): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT id, account, session_id, started_at, decided_at FROM cattophp_identity_verifications WHERE status = 'in_review' ORDER BY started_at, id",
        );
    }

    /**
     * An account being deleted: its identity checks and the record of its last allowed try go, and the checks it revoked
     * no longer say who did.
     *
     * @return array{checks:int,retries:int,revoked:int}
     */
    public function forget(string $account): array
    {
        return [
            'checks' => (int) $this->connection->executeStatement('DELETE FROM cattophp_identity_verifications WHERE account = :account', ['account' => $account]),
            'retries' => (int) $this->connection->executeStatement('DELETE FROM cattophp_identity_retries WHERE account = :account', ['account' => $account]),
            'revoked' => (int) $this->connection->executeStatement('UPDATE cattophp_identity_verifications SET revoked_by = NULL WHERE revoked_by = :account', ['account' => $account]),
        ];
    }
}
