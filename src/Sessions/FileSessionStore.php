<?php

declare(strict_types=1);

namespace CattoPHP\Sessions;

use DateTimeImmutable;
use RuntimeException;

/**
 * Sign-ins kept in one file for an installation without a database (a page with one owner has few): read and written
 * whole under a lock. Only its owner's account can read it.
 */
final class FileSessionStore implements SessionStore
{
    public function __construct(private readonly string $path)
    {
    }

    public function start(string $hash, string $publicId, string $accountId, ?string $email, ?string $requestedFrom, ?string $userAgent, DateTimeImmutable $at, DateTimeImmutable $expiresAt): SignInSession
    {
        $id = $this->change(static function (array &$file) use ($hash, $publicId, $accountId, $email, $requestedFrom, $userAgent, $at, $expiresAt): string {
            $id = (string) $file['next'];
            $file['next']++;
            $file['sessions'][$id] = ['hash' => $hash, 'public' => $publicId, 'account' => $accountId, 'email' => $email, 'from' => $requestedFrom, 'agent' => $userAgent,
                'started' => $at->format(DATE_ATOM), 'seen' => $at->format(DATE_ATOM), 'expires' => $expiresAt->format(DATE_ATOM), 'ended' => null];

            return $id;
        });

        return new SignInSession($id, $publicId, $accountId, $email, $requestedFrom, $userAgent, $at, $at, $expiresAt);
    }

    public function active(string $hash, DateTimeImmutable $now): ?SignInSession
    {
        foreach ($this->read()['sessions'] as $id => $row) {
            $session = self::session((string) $id, $row);
            if ($row['hash'] === $hash && $session->isActive($now)) {
                return $session;
            }
        }

        return null;
    }

    public function touch(string $id, DateTimeImmutable $now, DateTimeImmutable $expiresAt): void
    {
        $this->change(static function (array &$file) use ($id, $now, $expiresAt): void {
            if (isset($file['sessions'][$id])) {
                $file['sessions'][$id]['seen'] = $now->format(DATE_ATOM);
                $file['sessions'][$id]['expires'] = $expiresAt->format(DATE_ATOM);
            }
        });
    }

    public function activeFor(string $accountId, DateTimeImmutable $now): array
    {
        $found = [];
        foreach ($this->read()['sessions'] as $id => $row) {
            $session = self::session((string) $id, $row);
            if ($session->accountId === $accountId && $session->isActive($now)) {
                $found[] = $session;
            }
        }
        usort($found, static fn (SignInSession $a, SignInSession $b): int => [$b->startedAt, (int) $b->id] <=> [$a->startedAt, (int) $a->id]);

        return $found;
    }

    public function end(string $accountId, string $publicId, DateTimeImmutable $now): bool
    {
        return $this->change(static function (array &$file) use ($accountId, $publicId, $now): bool {
            foreach ($file['sessions'] as $id => $row) {
                if ($row['account'] === $accountId && $row['public'] === $publicId && $row['ended'] === null) {
                    $file['sessions'][$id]['ended'] = $now->format(DATE_ATOM);

                    return true;
                }
            }

            return false;
        });
    }

    public function endAllFor(string $accountId, DateTimeImmutable $now): int
    {
        return $this->change(static function (array &$file) use ($accountId, $now): int {
            $ended = 0;
            foreach ($file['sessions'] as $id => $row) {
                if ($row['account'] === $accountId && $row['ended'] === null) {
                    $file['sessions'][$id]['ended'] = $now->format(DATE_ATOM);
                    ++$ended;
                }
            }

            return $ended;
        });
    }

    public function endByHash(string $hash, DateTimeImmutable $now): void
    {
        $this->change(static function (array &$file) use ($hash, $now): void {
            foreach ($file['sessions'] as $id => $row) {
                if ($row['hash'] === $hash && $row['ended'] === null) {
                    $file['sessions'][$id]['ended'] = $now->format(DATE_ATOM);
                }
            }
        });
    }

    public function forgetOld(DateTimeImmutable $before): int
    {
        return $this->change(static function (array &$file) use ($before): int {
            $gone = 0;
            foreach ($file['sessions'] as $id => $row) {
                if (new DateTimeImmutable($row['expires']) < $before || ($row['ended'] !== null && new DateTimeImmutable($row['ended']) < $before)) {
                    unset($file['sessions'][$id]);
                    ++$gone;
                }
            }

            return $gone;
        });
    }

    /**
     * @template T
     * @param callable(array{next:int,sessions:array<string,array<string,mixed>>}&): T $change
     * @return T
     */
    private function change(callable $change): mixed
    {
        $folder = dirname($this->path);
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw new RuntimeException('The folder for sign-ins could not be made.');
        }
        $lock = fopen($this->path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('The sign-ins could not be locked.');
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

    /** @return array{next:int,sessions:array<string,array<string,mixed>>} */
    private function read(): array
    {
        $file = json_decode((string) @file_get_contents($this->path), true);
        if (!is_array($file) || !is_int($file['next'] ?? null) || !is_array($file['sessions'] ?? null)) {
            return ['next' => 1, 'sessions' => []];
        }
        $sessions = [];
        foreach ($file['sessions'] as $id => $row) {
            if (is_array($row) && is_string($row['hash'] ?? null) && is_string($row['public'] ?? null) && is_string($row['account'] ?? null)
                && is_string($row['started'] ?? null) && is_string($row['seen'] ?? null) && is_string($row['expires'] ?? null)) {
                $sessions[(string) $id] = $row;
            }
        }

        return ['next' => $file['next'], 'sessions' => $sessions];
    }

    /** @param array<string,mixed> $row */
    private static function session(string $id, array $row): SignInSession
    {
        $text = static fn (mixed $value): ?string => is_string($value) ? $value : null;

        return new SignInSession($id, (string) $row['public'], (string) $row['account'], $text($row['email'] ?? null), $text($row['from'] ?? null), $text($row['agent'] ?? null),
            new DateTimeImmutable((string) $row['started']), new DateTimeImmutable((string) $row['seen']), new DateTimeImmutable((string) $row['expires']),
            is_string($row['ended'] ?? null) ? new DateTimeImmutable($row['ended']) : null);
    }
}
