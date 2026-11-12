<?php

declare(strict_types=1);

namespace CattoPHP\Network;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * The published lists that tell what kind of address or email domain a visitor has, fetched into a folder by a
 * scheduled task and never at a visitor's request:
 *
 * - the Tor Project's exit addresses (check.torproject.org, every day);
 * - X4BNet's VPN networks and VPN-and-hosting networks (github.com/X4BNet/lists_vpn, MIT licence, every week);
 * - throwaway email domains (github.com/disposable-email-domains, CC0, every week).
 *
 * An address range list is kept as merged ranges in a binary file (two big-endian 32-bit numbers a range, sorted), so
 * an address is found by a binary search. A list that has not been fetched yet finds nothing. Fetching needs
 * symfony/http-client.
 */
final class ReputationLists
{
    /** Each list: where it comes from, how often it is fetched (seconds) and what it holds. */
    public const LISTS = [
        'tor' => ['url' => 'https://check.torproject.org/torbulkexitlist', 'every' => 86400, 'kind' => 'addresses', 'label' => 'Tor exits'],
        'vpn' => ['url' => 'https://raw.githubusercontent.com/X4BNet/lists_vpn/main/output/vpn/ipv4.txt', 'every' => 604800, 'kind' => 'ranges', 'label' => 'VPN networks'],
        'datacenter' => ['url' => 'https://raw.githubusercontent.com/X4BNet/lists_vpn/main/output/datacenter/ipv4.txt', 'every' => 604800, 'kind' => 'ranges', 'label' => 'VPN and hosting networks'],
        'disposable' => ['url' => 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/main/disposable_email_blocklist.conf', 'every' => 604800, 'kind' => 'domains', 'label' => 'Throwaway email domains'],
    ];

    /** @var array<string,array<string,true>> */
    private array $sets = [];

    /** @var array<string,string> */
    private array $ranges = [];

    /** @param string $folder where the lists are kept, created when the first is fetched */
    public function __construct(private readonly string $folder, private readonly ?HttpClientInterface $http = null)
    {
    }

    /**
     * Fetches each list that is due (or every list when forced); a list that cannot be fetched keeps its last copy.
     *
     * @return array<string,string> what happened to each list, in words
     */
    public function update(bool $force = false): array
    {
        $done = [];
        foreach (self::LISTS as $name => $list) {
            $file = $this->file($name);
            if (!$force && is_file($file) && time() - (int) filemtime($file) < $list['every'] - 300) {
                $done[$name] = 'up to date';
                continue;
            }
            try {
                $count = $this->store($name, $list['kind'], $this->fetch($list['url']));
                $done[$name] = $count . ' entries';
            } catch (Throwable $failed) {
                $done[$name] = 'not fetched (' . $failed->getMessage() . ')';
            }
        }

        return $done;
    }

    /** @return array<string,array{label:string,entries:int,fetched_at:?int}> each list's size and when it was fetched */
    public function status(): array
    {
        $status = [];
        foreach (self::LISTS as $name => $list) {
            $file = $this->file($name);
            $entries = 0;
            if (is_file($file)) {
                $entries = $list['kind'] === 'ranges' ? intdiv((int) filesize($file), 8) : count($this->set($name));
            }
            $status[$name] = ['label' => $list['label'], 'entries' => $entries, 'fetched_at' => is_file($file) ? (int) filemtime($file) : null];
        }

        return $status;
    }

    public function isTor(string $address): bool
    {
        return isset($this->set('tor')[$address]);
    }

    public function isVpn(string $address): bool
    {
        return $this->inRanges('vpn', $address);
    }

    /** A VPN or hosting network: a server, not a home or a phone. */
    public function isHosting(string $address): bool
    {
        return $this->inRanges('datacenter', $address);
    }

    /** A throwaway domain, or a subdomain of one. */
    public function isDisposable(string $domain): bool
    {
        $set = $this->set('disposable');
        $parts = explode('.', strtolower(trim($domain)));
        for ($i = 0; $i < count($parts) - 1; $i++) {
            if (isset($set[implode('.', array_slice($parts, $i))])) {
                return true;
            }
        }

        return false;
    }

    /** Stores a list's text as fetched (also for tests): the entries it kept. */
    public function store(string $name, string $kind, string $text): int
    {
        if (!is_dir($this->folder) && !@mkdir($this->folder, 0775, true) && !is_dir($this->folder)) {
            throw new RuntimeException('cannot write ' . $this->folder);
        }
        $lines = array_values(array_filter(
            array_map(static fn(string $line): string => strtolower(trim((string) preg_replace('/#.*$/', '', $line))), preg_split('/\R/', $text) ?: []),
            static fn(string $line): bool => $line !== '',
        ));
        if ($lines === []) {
            throw new RuntimeException('the list was empty');
        }
        if ($kind === 'ranges') {
            $ranges = [];
            foreach ($lines as $line) {
                $range = self::cidr($line);
                if ($range !== null) {
                    $ranges[] = $range;
                }
            }
            usort($ranges, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            $merged = [];
            foreach ($ranges as [$start, $end]) {
                $last = count($merged) - 1;
                if ($last >= 0 && $start <= $merged[$last][1] + 1) {
                    $merged[$last][1] = max($merged[$last][1], $end);
                    continue;
                }
                $merged[] = [$start, $end];
            }
            $binary = '';
            foreach ($merged as [$start, $end]) {
                $binary .= pack('NN', $start, $end);
            }
            $this->write($name, $binary);
            unset($this->ranges[$name]);

            return count($merged);
        }
        $this->write($name, implode("\n", array_values(array_unique($lines))) . "\n");
        unset($this->sets[$name]);

        return count(array_unique($lines));
    }

    private function fetch(string $url): string
    {
        if ($this->http === null) {
            throw new RuntimeException('no HTTP client');
        }
        $response = $this->http->request('GET', $url, ['timeout' => 30, 'headers' => ['User-Agent' => 'CattoPHP network lists']]);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('HTTP ' . $response->getStatusCode());
        }

        return $response->getContent();
    }

    private function write(string $name, string $content): void
    {
        $file = $this->file($name);
        $temporary = $file . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $content) === false || !rename($temporary, $file)) {
            @unlink($temporary);
            throw new RuntimeException('cannot write ' . $file);
        }
    }

    /** @return array<string,true> */
    private function set(string $name): array
    {
        if (!isset($this->sets[$name])) {
            $file = $this->file($name);
            $this->sets[$name] = is_file($file)
                ? array_fill_keys(array_filter(explode("\n", (string) file_get_contents($file)), static fn(string $line): bool => $line !== ''), true)
                : [];
        }

        return $this->sets[$name];
    }

    private function inRanges(string $name, string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $this->ranges[$name] ??= is_file($this->file($name)) ? (string) file_get_contents($this->file($name)) : '';
        $data = $this->ranges[$name];
        $value = (int) sprintf('%u', ip2long($address));
        $low = 0;
        $high = intdiv(strlen($data), 8) - 1;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            /** @var array{1:int,2:int} $range */
            $range = unpack('N2', $data, $middle * 8);
            if ($value < $range[1]) {
                $high = $middle - 1;
            } elseif ($value > $range[2]) {
                $low = $middle + 1;
            } else {
                return true;
            }
        }

        return false;
    }

    /** @return array{0:int,1:int}|null an IPv4 range from "1.2.3.0/24" or a single address */
    private static function cidr(string $line): ?array
    {
        [$address, $bits] = array_pad(explode('/', $line, 2), 2, '32');
        $start = ip2long($address);
        if ($start === false || !ctype_digit($bits) || (int) $bits > 32) {
            return null;
        }
        $size = 2 ** (32 - (int) $bits);
        $start = (int) (intdiv((int) sprintf('%u', $start), $size) * $size);

        return [$start, $start + $size - 1];
    }

    private function file(string $name): string
    {
        return rtrim($this->folder, '/') . '/' . $name . (self::LISTS[$name]['kind'] === 'ranges' ? '.ranges' : '.txt');
    }
}
