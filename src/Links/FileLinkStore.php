<?php

declare(strict_types=1);

namespace CattoPHP\Links;

use DateTimeImmutable;
use RuntimeException;

/**
 * Links kept in one file for an installation without a database (a page with one owner has few): read and written whole
 * under a lock, so a link is used once even by two attempts at the same moment. Only its owner's account can read it.
 */
final class FileLinkStore implements LinkStore
{
    public function __construct(private readonly string $path)
    {
    }

    public function add(string $hash, string $purpose, ?string $email, ?string $accountId, array $context, ?string $requestedFrom, DateTimeImmutable $at, DateTimeImmutable $expiresAt): string
    {
        return $this->change(static function (array &$file) use ($hash, $purpose, $email, $accountId, $context, $requestedFrom, $at, $expiresAt): string {
            $id = (string) $file['next'];
            $file['next']++;
            $file['links'][$id] = ['hash' => $hash, 'purpose' => $purpose, 'email' => $email, 'account' => $accountId, 'context' => $context, 'from' => $requestedFrom,
                'created' => $at->format(DATE_ATOM), 'expires' => $expiresAt->format(DATE_ATOM), 'used' => null];

            return $id;
        });
    }

    public function find(string $hash, string $purpose): ?Link
    {
        foreach ($this->read()['links'] as $id => $row) {
            if ($row['hash'] === $hash && $row['purpose'] === $purpose) {
                return self::link((string) $id, $row);
            }
        }

        return null;
    }

    public function usable(string $hash, string $purpose, DateTimeImmutable $now, bool $lock = false): ?Link
    {
        $link = $this->find($hash, $purpose);

        return $link !== null && $link->isUsable($now) ? $link : null;
    }

    public function usableById(string $id, string $purpose, DateTimeImmutable $now, bool $lock = false): ?Link
    {
        $row = $this->read()['links'][$id] ?? null;
        $link = $row === null ? null : self::link($id, $row);

        return $link !== null && $link->purpose === $purpose && $link->isUsable($now) ? $link : null;
    }

    public function changeContext(string $id, array $context): void
    {
        $this->change(static function (array &$file) use ($id, $context): void {
            if (isset($file['links'][$id])) {
                $file['links'][$id]['context'] = $context;
            }
        });
    }

    public function use(string $id, DateTimeImmutable $now): bool
    {
        return $this->change(static function (array &$file) use ($id, $now): bool {
            if (!isset($file['links'][$id]) || $file['links'][$id]['used'] !== null) {
                return false;
            }
            $file['links'][$id]['used'] = $now->format(DATE_ATOM);

            return true;
        });
    }

    public function finishOpen(string $email, string $purpose, DateTimeImmutable $now): void
    {
        $this->change(static function (array &$file) use ($email, $purpose, $now): void {
            foreach ($file['links'] as $id => $row) {
                if ($row['email'] === $email && $row['purpose'] === $purpose && $row['used'] === null) {
                    $file['links'][$id]['used'] = $now->format(DATE_ATOM);
                }
            }
        });
    }

    public function forgetOpen(string $accountId, string $purpose): void
    {
        $this->change(static function (array &$file) use ($accountId, $purpose): void {
            foreach ($file['links'] as $id => $row) {
                if ($row['account'] === $accountId && $row['purpose'] === $purpose && $row['used'] === null) {
                    unset($file['links'][$id]);
                }
            }
        });
    }

    public function forgetAccount(string $accountId, ?string $email): int
    {
        $email = mb_strtolower(trim((string) $email));

        return $this->change(static function (array &$file) use ($accountId, $email): int {
            $gone = 0;
            foreach ($file['links'] as $id => $row) {
                if ($row['account'] === $accountId || ($row['account'] === null && $email !== '' && is_string($row['email']) && mb_strtolower($row['email']) === $email)) {
                    unset($file['links'][$id]);
                    ++$gone;
                }
            }

            return $gone;
        });
    }

    public function forget(string $hash): void
    {
        $this->change(static function (array &$file) use ($hash): void {
            foreach ($file['links'] as $id => $row) {
                if ($row['hash'] === $hash && $row['used'] === null) {
                    unset($file['links'][$id]);
                }
            }
        });
    }

    public function countForEmail(string $email, DateTimeImmutable $since, array $except = []): int
    {
        return $this->count('email', $email, $since, $except);
    }

    public function countFrom(string $requestedFrom, DateTimeImmutable $since, array $except = []): int
    {
        return $this->count('from', $requestedFrom, $since, $except);
    }

    public function forgetOld(DateTimeImmutable $before): int
    {
        return $this->change(static function (array &$file) use ($before): int {
            $gone = 0;
            foreach ($file['links'] as $id => $row) {
                if (new DateTimeImmutable($row['expires']) < $before || ($row['used'] !== null && new DateTimeImmutable($row['used']) < $before)) {
                    unset($file['links'][$id]);
                    ++$gone;
                }
            }

            return $gone;
        });
    }

    /** @param list<string> $except */
    private function count(string $field, string $value, DateTimeImmutable $since, array $except): int
    {
        return count(array_filter($this->read()['links'], static fn (array $row): bool => $row[$field] === $value && new DateTimeImmutable($row['created']) > $since && !in_array($row['purpose'], $except, true)));
    }

    /**
     * Runs a change on the whole file under its lock and writes it back.
     *
     * @template T
     * @param callable(array{next:int,links:array<string,array<string,mixed>>}&): T $change
     * @return T
     */
    private function change(callable $change): mixed
    {
        $folder = dirname($this->path);
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw new RuntimeException('The folder for links could not be made.');
        }
        $lock = fopen($this->path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('The links could not be locked.');
        }
        try {
            $file = $this->read();
            $result = $change($file);
            $temporary = $this->path . '.' . bin2hex(random_bytes(4));
            file_put_contents($temporary, json_encode($file, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            chmod($temporary, 0600);
            rename($temporary, $this->path);

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{next:int,links:array<string,array<string,mixed>>} */
    private function read(): array
    {
        $file = json_decode((string) @file_get_contents($this->path), true);
        if (!is_array($file) || !is_int($file['next'] ?? null) || !is_array($file['links'] ?? null)) {
            return ['next' => 1, 'links' => []];
        }
        $links = [];
        foreach ($file['links'] as $id => $row) {
            if (is_array($row) && is_string($row['hash'] ?? null) && is_string($row['purpose'] ?? null) && is_string($row['created'] ?? null) && is_string($row['expires'] ?? null)) {
                $links[(string) $id] = $row;
            }
        }

        return ['next' => $file['next'], 'links' => $links];
    }

    /** @param array<string,mixed> $row */
    private static function link(string $id, array $row): Link
    {
        $context = is_array($row['context'] ?? null) ? $row['context'] : [];
        $keyed = [];
        foreach ($context as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return new Link($id, (string) $row['hash'], (string) $row['purpose'], is_string($row['email'] ?? null) ? $row['email'] : null, is_string($row['account'] ?? null) ? $row['account'] : null,
            $keyed, is_string($row['from'] ?? null) ? $row['from'] : null, new DateTimeImmutable((string) $row['created']), new DateTimeImmutable((string) $row['expires']),
            is_string($row['used'] ?? null) ? new DateTimeImmutable($row['used']) : null);
    }
}
