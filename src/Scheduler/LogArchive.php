<?php

declare(strict_types=1);

namespace CattoPHP\Scheduler;

use CattoPHP\Foundation\Duration;
use CattoPHP\Settings\Settings;
use DateTimeImmutable;
use RuntimeException;

/**
 * A project's logs, archived: every .log in its logs folder that holds anything is moved into archive/<name>-<day>.log.gz
 * and started afresh with the permissions it had, and archives older than LOG_ARCHIVES_KEPT_FOR are deleted. The
 * scheduler runs it just after midnight (LogArchiveTask).
 */
final class LogArchive
{
    public const KEEP = 'LOG_ARCHIVES_KEPT_FOR';
    public const DEFAULT_KEEP = '2w';
    public const FOLDER = 'archive';
    private const CHUNK = 1048576;

    /**
     * @param string $folder the folder the logs are in
     * @param Settings|null $settings where how long archives are kept is read from; null keeps them two weeks
     * @param string $first the project's main log, listed first
     */
    public function __construct(private readonly string $folder, private readonly ?Settings $settings = null, private readonly string $first = 'application.log')
    {
    }

    public function logPath(): string
    {
        return $this->folder . '/' . $this->first;
    }

    public function archiveFolder(): string
    {
        return $this->folder . '/' . self::FOLDER;
    }

    /** How long archives are kept: what Settings saved, else .env, else two weeks. */
    public function keep(): Duration
    {
        return $this->settings?->duration(self::KEEP) ?? Duration::parse(self::DEFAULT_KEEP);
    }

    /**
     * Archives every log that holds anything and deletes the archives past the keep period.
     *
     * @return array{archived:array<string,array{archive:string,bytes:int}>,deleted:int} each log archived, by its name
     */
    public function run(DateTimeImmutable $now): array
    {
        $folder = $this->archiveFolder();
        if (!is_dir($folder) && !@mkdir($folder, 0775, true) && !is_dir($folder)) throw new RuntimeException('The log archive folder ' . $folder . ' cannot be made.');
        // An archive a stopped run left uncompressed is finished first.
        foreach (glob($folder . '/*.log') ?: [] as $left) $this->compress($left);

        $archived = [];
        // Named after the day it mostly holds: the day before for the run just after midnight, the same day for a run
        // made later in the day.
        $day = $now->modify('-12 hours')->format('Y-m-d');
        foreach ($this->logs() as $log) {
            clearstatcache(true, $log);
            if (filesize($log) === 0) continue;
            $bytes = (int) filesize($log);
            $mode = fileperms($log) & 0777;
            $moved = $this->freeName($folder, basename($log, '.log'), $day);
            if (!@rename($log, $moved)) throw new RuntimeException(basename($log) . ' could not be moved into ' . $folder . '.');
            // The new log keeps the old one's permissions, so whichever process writes next can still write to it.
            if (!is_file($log)) {
                @touch($log);
                @chmod($log, $mode);
            }
            $archived[basename($log)] = ['archive' => basename($this->compress($moved)), 'bytes' => $bytes];
        }

        return ['archived' => $archived, 'deleted' => $this->prune($now)];
    }

    /** What a run did, in words, as the scheduler records it. */
    public function summary(DateTimeImmutable $now): string
    {
        $done = $this->run($now);
        $archived = $done['archived'] === [] ? 'The logs were empty, so nothing was archived.'
            : 'Archived ' . implode(', ', array_map(static fn(string $log, array $one): string => self::size($one['bytes']) . ' of ' . $log . ' as ' . $one['archive'], array_keys($done['archived']), $done['archived'])) . ', each started afresh.';

        return $archived . ' Deleted ' . $done['deleted'] . ' archive' . ($done['deleted'] === 1 ? '' : 's') . ' older than ' . $this->keep()->words() . '.';
    }

    /**
     * Where the logs and their archives are and how big they are, for a project's Settings.
     *
     * @return array{log:string,log_bytes:int,others:list<array{path:string,bytes:int}>,folder:string,archives:int,archive_bytes:int,oldest:?string,newest:?string}
     */
    public function status(): array
    {
        $log = $this->logPath();
        clearstatcache();
        $others = array_values(array_map(static fn(string $path): array => ['path' => $path, 'bytes' => (int) filesize($path)],
            array_filter($this->logs(), fn(string $path): bool => basename($path) !== $this->first)));
        $archives = $this->archives();
        $names = array_map('basename', $archives);
        // By the name without its ending, so a day's second archive (-2) comes after its first.
        usort($names, static fn(string $a, string $b): int => strcmp(substr($a, 0, -7), substr($b, 0, -7)));

        return [
            'log' => $log,
            'log_bytes' => is_file($log) ? (int) filesize($log) : 0,
            'others' => $others,
            'folder' => $this->archiveFolder(),
            'archives' => count($archives),
            'archive_bytes' => array_sum(array_map(static fn(string $path): int => (int) filesize($path), $archives)),
            'oldest' => $names[0] ?? null,
            'newest' => $names === [] ? null : $names[count($names) - 1],
        ];
    }

    /** A size in words: "4.9 GB", "812 KB", "0 bytes". */
    public static function size(int $bytes): string
    {
        foreach (['GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024] as $unit => $size) {
            if ($bytes >= $size) return rtrim(rtrim(number_format($bytes / $size, 1, '.', ''), '0'), '.') . ' ' . $unit;
        }

        return $bytes . ($bytes === 1 ? ' byte' : ' bytes');
    }

    /** @return list<string> the logs in the folder, the main log first */
    private function logs(): array
    {
        $logs = array_values(array_filter(glob($this->folder . '/*.log') ?: [], 'is_file'));
        usort($logs, fn(string $a, string $b): int => [basename($a) !== $this->first, $a] <=> [basename($b) !== $this->first, $b]);

        return $logs;
    }

    /** @return list<string> */
    private function archives(): array
    {
        return glob($this->archiveFolder() . '/*.log.gz') ?: [];
    }

    /** application-2026-10-27.log, or -2, -3 and so on added when that day already has an archive of that log. */
    private function freeName(string $folder, string $stem, string $day): string
    {
        $path = $folder . '/' . $stem . '-' . $day . '.log';
        if (!file_exists($path) && !file_exists($path . '.gz')) return $path;
        for ($n = 2; ; $n++) {
            $path = $folder . '/' . $stem . '-' . $day . '-' . $n . '.log';
            if (!file_exists($path) && !file_exists($path . '.gz')) return $path;
        }
    }

    /** Compresses a moved log beside itself and removes the uncompressed copy. */
    private function compress(string $path): string
    {
        $target = $path . '.gz';
        $partial = $target . '.part';
        $in = @fopen($path, 'rb');
        $out = @gzopen($partial, 'wb6');
        if ($in === false || $out === false) throw new RuntimeException('The log archive ' . basename($target) . ' could not be written.');
        while (!feof($in)) {
            $chunk = fread($in, self::CHUNK);
            if ($chunk === false) break;
            if ($chunk !== '' && gzwrite($out, $chunk) === false) throw new RuntimeException('The log archive ' . basename($target) . ' could not be written.');
        }
        fclose($in);
        gzclose($out);
        if (!@rename($partial, $target)) throw new RuntimeException('The log archive ' . basename($target) . ' could not be finished.');
        @unlink($path);

        return $target;
    }

    private function prune(DateTimeImmutable $now): int
    {
        $before = $this->keep()->before($now)->getTimestamp();
        $deleted = 0;
        foreach ($this->archives() as $archive) {
            if ((int) filemtime($archive) < $before && @unlink($archive)) $deleted++;
        }

        return $deleted;
    }
}
