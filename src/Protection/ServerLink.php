<?php

declare(strict_types=1);

namespace CattoPHP\Protection;

use RuntimeException;
use Throwable;

/**
 * The link to a firewall on the server, by two files in a folder the site can write: the site only appends what it asks
 * (ban or lift a network) to requests.log, and reads what the server last wrote to view.json (the bans it holds, the
 * probes it saw). The part that runs as root lives on the server, never in the site, so a site never needs root and a
 * server without it simply never answers.
 */
class ServerLink
{
    /** How old the view may be before the pages say it is out of date. */
    public const STALE_AFTER = 600;

    public function __construct(private readonly string $folder, private readonly PathMask $paths = new PathMask())
    {
    }

    public function ban(string $network): void
    {
        $this->request('ban', $network);
    }

    public function release(string $network): void
    {
        $this->request('lift', $network);
    }

    /**
     * What the server last said.
     *
     * @return array{available:bool,stale:bool,written_at:string,bans:list<array{address:string,why:string,since:string}>,probes:list<array{at:string,address:string,path:string}>,probes_today:int}
     */
    public function view(): array
    {
        $none = ['available' => false, 'stale' => false, 'written_at' => '', 'bans' => [], 'probes' => [], 'probes_today' => 0];
        $file = $this->folder . '/view.json';
        if (!is_readable($file)) return $none;
        try {
            $view = json_decode((string) file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $none;
        }
        if (!is_array($view)) return $none;
        $written = strtotime((string) ($view['written_at'] ?? '')) ?: (int) filemtime($file);

        return [
            'available' => true,
            'stale' => time() - $written > self::STALE_AFTER,
            'written_at' => gmdate(DATE_ATOM, $written),
            'bans' => array_values(array_map(static fn(array $ban): array => ['address' => (string) ($ban['address'] ?? ''), 'why' => (string) ($ban['why'] ?? ''), 'since' => (string) ($ban['since'] ?? '')], array_filter((array) ($view['bans'] ?? []), 'is_array'))),
            'probes' => array_values(array_map(fn(array $probe): array => ['at' => (string) ($probe['at'] ?? ''), 'address' => (string) ($probe['address'] ?? ''), 'path' => $this->paths->masked((string) ($probe['path'] ?? '/'))], array_filter((array) ($view['probes'] ?? []), 'is_array'))),
            'probes_today' => (int) ($view['probes_today'] ?? 0),
        ];
    }

    /** Whether the server's firewall holds this address, by this site's lockout or any other rule. */
    public function isBanned(string $subject): bool
    {
        foreach ($this->view()['bans'] as $ban) {
            if (Addresses::contains($ban['address'], (string) strtok($subject, '/'))) return true;
        }

        return false;
    }

    public function folder(): string
    {
        return $this->folder;
    }

    private function request(string $what, string $network): void
    {
        if (!is_dir($this->folder) && !@mkdir($this->folder, 0775, true) && !is_dir($this->folder)) throw new RuntimeException('The folder for the server\'s firewall could not be made.');
        $line = json_encode(['at' => gmdate(DATE_ATOM), 'do' => $what, 'network' => $network], JSON_THROW_ON_ERROR) . "\n";
        if (@file_put_contents($this->folder . '/requests.log', $line, FILE_APPEND | LOCK_EX) === false) throw new RuntimeException('The request to the server\'s firewall could not be written.');
    }
}
