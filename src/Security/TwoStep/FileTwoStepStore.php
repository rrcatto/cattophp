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
 */
final class FileTwoStepStore implements TwoStepStore
{
    public function __construct(private readonly string $path)
    {
    }

    public function find(string $accountId): ?TwoStepRecord
    {
        $text = @file_get_contents($this->file($accountId));

        return is_string($text) ? self::read($text) : null;
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
            $after = $change(is_string($text) ? self::read($text) : null);
            if ($after !== null) {
                $value = ['secret' => $after->secret, 'last_counter' => $after->lastCounter];
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

    public function forget(string $accountId): void
    {
        @unlink($this->file($accountId));
    }

    private function file(string $accountId): string
    {
        return str_replace('{account}', hash('sha256', $accountId), $this->path);
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
