<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * The overrides the Settings page saves, for an installation without a database: one JSON file in its storage folder
 * (`settings.json`), readable by the application's own account alone (the folder made 0700, the file 0600). Each change
 * reads the file, changes it and writes it whole to a file beside it, which then takes its place, all under one lock
 * (`settings.json.lock`), so two saves at once never lose either and a reader never sees half a file. A secret's value
 * arrives encrypted (Settings encrypts it, as for the database) and is kept as it arrives.
 *
 * A file that cannot be read, or is not as this store writes it, never stops the application: nothing saved applies,
 * so every setting has its starting value, the fault is logged and readProblem() says it in words for the Settings page.
 * The next save puts the unreadable file aside beside it (`settings.json.damaged-<when>`), never losing it, and starts
 * afresh. A save that cannot be written (a full disk, a folder the account cannot write) is refused in words, the file
 * left as it was.
 */
final class FileSettingsStore implements SettingsStore, SettingsStoreProblem
{
    private const FORMAT = 1;

    private ?string $problem = null;

    private ?string $logged = null;

    public function __construct(private readonly string $path, private readonly ?LoggerInterface $logger = null)
    {
    }

    public function all(): array
    {
        $stored = [];
        foreach ($this->read() as $name => $row) {
            $stored[$name] = new StoredSetting($row['value'], $row['secret'], $row['updated_at'], $row['updated_by']);
        }

        return $stored;
    }

    public function save(string $name, string $value, bool $secret, DateTimeImmutable $at, ?string $by): void
    {
        $this->change(static function (array &$settings) use ($name, $value, $secret, $at, $by): void {
            $settings[$name] = ['value' => $value, 'secret' => $secret, 'updated_at' => $at->format('Y-m-d H:i:sP'), 'updated_by' => $by];
        });
    }

    public function remove(string $name): bool
    {
        if (!isset($this->read()[$name])) {
            return false;
        }

        return $this->change(static function (array &$settings) use ($name): bool {
            $had = isset($settings[$name]);
            unset($settings[$name]);

            return $had;
        });
    }

    public function forgetAccount(string $accountId): int
    {
        $saved = array_filter($this->read(), static fn (array $row): bool => $row['updated_by'] === $accountId);
        if ($saved === []) {
            return 0;
        }

        return $this->change(static function (array &$settings) use ($accountId): int {
            $changed = 0;
            foreach ($settings as $name => $row) {
                if ($row['updated_by'] === $accountId) {
                    $settings[$name]['updated_by'] = null;
                    ++$changed;
                }
            }

            return $changed;
        });
    }

    public function isWritable(): bool
    {
        return true;
    }

    public function readProblem(): ?string
    {
        return $this->problem;
    }

    /**
     * Runs a change on every saved setting under the lock and writes the file back whole.
     *
     * @template T
     * @param callable(array<string,array{value:string,secret:bool,updated_at:string,updated_by:?string}>&): T $change
     * @return T
     */
    private function change(callable $change): mixed
    {
        $folder = dirname($this->path);
        if (!is_dir($folder) && !@mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw self::notSaved('the storage folder could not be made.');
        }
        $lockPath = $this->path . '.lock';
        $newLock = !is_file($lockPath);
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw self::notSaved('they could not be locked against another save.');
        }
        if ($newLock) {
            @chmod($lockPath, 0600);
        }
        try {
            $settings = $this->read();
            if ($this->problem !== null && file_exists($this->path)) {
                // What could not be read is kept beside the new file, never written over.
                $aside = $this->path . '.damaged-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(2));
                if (!@rename($this->path, $aside)) {
                    throw self::notSaved('the saved settings could not be read, and the unreadable file could not be put aside.');
                }
                $this->logger?->warning('The unreadable file of saved settings was put aside as {aside}; saving starts afresh.', ['aside' => $aside]);
            }
            $result = $change($settings);
            ksort($settings);
            $this->write(['format' => self::FORMAT, 'settings' => $settings === [] ? new \stdClass() : $settings]);
            $this->problem = null;
            $this->logged = null;

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string,mixed> $file */
    private function write(array $file): void
    {
        $text = json_encode($file, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $temporary = $this->path . '.' . bin2hex(random_bytes(4)) . '.new';
        $handle = @fopen($temporary, 'x');
        try {
            // Made readable by this account alone before anything is written into it.
            if ($handle === false || !@chmod($temporary, 0600) || @fwrite($handle, $text) !== strlen($text) || !@fflush($handle)) {
                throw self::notSaved('the storage folder could not be written: is the disk full?');
            }
            fclose($handle);
            $handle = false;
            if (!@rename($temporary, $this->path)) {
                throw self::notSaved('the new file could not take the old one\'s place.');
            }
        } finally {
            if ($handle !== false) {
                fclose($handle);
            }
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Every saved setting as the file holds it; none, with the problem noted and logged, when it cannot be read.
     *
     * @return array<string,array{value:string,secret:bool,updated_at:string,updated_by:?string}>
     */
    private function read(): array
    {
        $this->problem = null;
        if (!file_exists($this->path)) {
            return [];
        }
        $text = @file_get_contents($this->path);
        if ($text === false) {
            return $this->failed('The file of saved settings could not be read: it must belong to the account the application runs as.');
        }
        try {
            $file = json_decode($text, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->failed('The file of saved settings is damaged: it is not as CattoPHP writes it.');
        }
        if (!is_array($file) || ($file['format'] ?? null) !== self::FORMAT || !is_array($file['settings'] ?? null)) {
            return $this->failed('The file of saved settings is damaged: it is not as CattoPHP writes it.');
        }
        $settings = [];
        $passedOver = 0;
        foreach ($file['settings'] as $name => $row) {
            if (!is_string($name) || preg_match('/^[A-Z][A-Z0-9_]*$/', $name) !== 1 || !is_array($row) || !is_string($row['value'] ?? null) || !is_bool($row['secret'] ?? null)
                || !is_string($row['updated_at'] ?? null) || !(is_string($row['updated_by'] ?? null) || ($row['updated_by'] ?? null) === null)) {
                ++$passedOver;
                continue;
            }
            $settings[$name] = ['value' => $row['value'], 'secret' => $row['secret'], 'updated_at' => $row['updated_at'], 'updated_by' => $row['updated_by'] ?? null];
        }
        if ($passedOver > 0) {
            $this->failed(sprintf('The file of saved settings is damaged: %s in it could not be read and %s passed over.', $passedOver === 1 ? '1 saved setting' : $passedOver . ' saved settings', $passedOver === 1 ? 'was' : 'were'));

            return [];
        }

        return $settings;
    }

    /** @return array{} nothing saved applies; the problem is kept and logged once */
    private function failed(string $problem): array
    {
        $this->problem = $problem;
        if ($this->logged !== $problem) {
            $this->logged = $problem;
            $this->logger?->error('{problem} The starting values apply until it is put right or the Settings page saves again.', ['problem' => $problem, 'file' => $this->path]);
        }

        return [];
    }

    private static function notSaved(string $why): SettingsRefused
    {
        return new SettingsRefused(['' => 'The settings could not be saved: ' . $why . ' Nothing was changed.']);
    }
}
