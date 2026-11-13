<?php

declare(strict_types=1);

namespace CattoPHP\Security\TwoStep;

use DateTimeImmutable;
use RuntimeException;

/**
 * Two-step sign-in in a file only the site's own account can read, for an installation without a database. The path
 * names one file per account with `{account}` (replaced by a hash of the account's id), or is one file, for an
 * installation with one person. The file holds `{"secret": …, "last_counter": …}`, and `recovery` when there are
 * recovery codes. Deleting the file is how its person starts again with a new key.
 *
 * One file holds one person's key, and says whose (`account`, a hash of the account's id): another account finds no key
 * there, forgetting another account leaves it alone, and setting one up for another account is refused, since it would
 * replace the person's key. A file written before it said whose is the one person's, whoever asks, and is never forgotten
 * for anyone until it says.
 */
final class FileTwoStepStore implements TwoStepStore
{
    public function __construct(private readonly string $path)
    {
    }

    public function find(string $accountId): ?TwoStepRecord
    {
        $text = @file_get_contents($this->file($accountId));
        if (!is_string($text)) {
            return null;
        }

        return $this->owner($text) === false || $this->isOwnedBy($text, $accountId) ? self::read($text) : null;
    }

    public function change(string $accountId, callable $change): void
    {
        $file = $this->file($accountId);
        $folder = dirname($file);
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw new RuntimeException('The folder for two-step sign-in could not be made.');
        }
        $lock = fopen($file . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Two-step sign-in could not be locked.');
        }
        try {
            $text = @file_get_contents($file);
            if (is_string($text) && $this->oneFile() && $this->owner($text) !== false && !$this->isOwnedBy($text, $accountId)) {
                throw new RuntimeException('The two-step file holds another person\'s key: an installation with more than one person names a file per account, with {account} in cattophp.sign_in.two_step_file.');
            }
            $after = $change(is_string($text) ? self::read($text) : null);
            if ($after !== null) {
                $value = ['secret' => $after->secret, 'last_counter' => $after->lastCounter];
                if ($this->oneFile()) {
                    $value['account'] = hash('sha256', $accountId);
                }
                if ($after->setUpAt !== null) {
                    $value['set_up_at'] = $after->setUpAt->format(DATE_ATOM);
                }
                if ($after->recoveryHashes !== []) {
                    $value['recovery'] = $after->recoveryHashes;
                }
                $temporary = $file . '.' . bin2hex(random_bytes(4));
                file_put_contents($temporary, json_encode($value, JSON_THROW_ON_ERROR));
                chmod($temporary, 0600);
                rename($temporary, $file);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function forget(string $accountId): bool
    {
        $file = $this->file($accountId);
        if (!is_file($file)) {
            return false;
        }
        if ($this->oneFile()) {
            $text = @file_get_contents($file);
            if (!is_string($text) || !$this->isOwnedBy($text, $accountId)) {
                // Another person's key, or one that does not say whose: never forgotten for this account.
                return false;
            }
        }

        return @unlink($file);
    }

    private function file(string $accountId): string
    {
        return str_replace('{account}', hash('sha256', $accountId), $this->path);
    }

    /** Whether the path is one file for one person, not a file per account. */
    private function oneFile(): bool
    {
        return !str_contains($this->path, '{account}');
    }

    /** Whose key a file holds, as a hash of the account's id; false when it does not say (one file per account never does). */
    private function owner(string $text): string|false
    {
        $value = json_decode($text, true);

        return is_array($value) && is_string($value['account'] ?? null) ? $value['account'] : false;
    }

    private function isOwnedBy(string $text, string $accountId): bool
    {
        $owner = $this->owner($text);

        return $owner !== false && hash_equals($owner, hash('sha256', $accountId));
    }

    private static function read(string $text): ?TwoStepRecord
    {
        $value = json_decode($text, true);
        if (!is_array($value) || !is_string($value['secret'] ?? null) || !is_int($value['last_counter'] ?? null)) {
            return null;
        }
        $recovery = is_array($value['recovery'] ?? null) ? array_values(array_filter($value['recovery'], 'is_string')) : [];

        $setUpAt = is_string($value['set_up_at'] ?? null) ? DateTimeImmutable::createFromFormat(DATE_ATOM, $value['set_up_at']) : false;

        return new TwoStepRecord($value['secret'], $value['last_counter'], $recovery, $setUpAt === false ? null : $setUpAt);
    }
}
