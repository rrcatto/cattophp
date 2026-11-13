<?php

declare(strict_types=1);

namespace CattoPHP\Security\TwoStep;

use DateTimeImmutable;
use CattoPHP\Security\SecretCipher;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/** Two-step sign-in in the database (`cattophp_two_step`), the key encrypted. */
final class DbalTwoStepStore implements TwoStepStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SecretCipher $cipher,
        private readonly ClockInterface $clock,
    ) {
    }

    public function find(string $accountId): ?TwoStepRecord
    {
        $row = $this->connection->fetchAssociative('SELECT secret_encrypted, last_counter, recovery_hashes, set_up_at FROM cattophp_two_step WHERE account_id = ?', [$accountId]);

        return $row === false ? null : $this->record($row);
    }

    public function change(string $accountId, callable $change): void
    {
        $this->connection->transactional(function (Connection $connection) use ($accountId, $change): void {
            $row = $connection->fetchAssociative('SELECT secret_encrypted, last_counter, recovery_hashes, set_up_at FROM cattophp_two_step WHERE account_id = ? FOR UPDATE', [$accountId]);
            $before = $row === false ? null : $this->record($row);
            $after = $change($before);
            if ($after === null) {
                return;
            }
            $at = $this->clock->now()->format('Y-m-d H:i:sP');
            // When a key is set up is the record's, as every store keeps it; a record without one was set up now.
            $setUpAt = ($after->setUpAt ?? $this->clock->now())->format('Y-m-d H:i:sP');
            $connection->executeStatement(
                'INSERT INTO cattophp_two_step (account_id, secret_encrypted, last_counter, recovery_hashes, set_up_at, updated_at)
                 VALUES (:account, :secret, :counter, :recovery, :set_up_at, :at)
                 ON CONFLICT (account_id) DO UPDATE SET secret_encrypted = EXCLUDED.secret_encrypted, last_counter = EXCLUDED.last_counter,
                     recovery_hashes = EXCLUDED.recovery_hashes, updated_at = EXCLUDED.updated_at,
                     set_up_at = CASE WHEN cattophp_two_step.secret_encrypted = EXCLUDED.secret_encrypted THEN cattophp_two_step.set_up_at ELSE EXCLUDED.set_up_at END',
                [
                    'account' => $accountId,
                    'secret' => $before !== null && $before->secret === $after->secret ? (string) $row['secret_encrypted'] : $this->cipher->encrypt($after->secret),
                    'counter' => $after->lastCounter,
                    'recovery' => json_encode($after->recoveryHashes, JSON_THROW_ON_ERROR),
                    'at' => $at,
                    'set_up_at' => $setUpAt,
                ],
            );
        });
    }

    public function forget(string $accountId): bool
    {
        return $this->connection->executeStatement('DELETE FROM cattophp_two_step WHERE account_id = ?', [$accountId]) > 0;
    }

    /** @param array<string,mixed> $row */
    private function record(array $row): TwoStepRecord
    {
        $hashes = json_decode((string) $row['recovery_hashes'], true);

        return new TwoStepRecord(
            $this->cipher->decrypt((string) $row['secret_encrypted']),
            (int) $row['last_counter'],
            is_array($hashes) ? array_values(array_filter($hashes, 'is_string')) : [],
            new DateTimeImmutable((string) $row['set_up_at']),
        );
    }
}
