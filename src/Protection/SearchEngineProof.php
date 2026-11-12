<?php

declare(strict_types=1);

namespace CattoPHP\Protection;

use CattoPHP\Http\VisitorAgent;

/**
 * Whether an address calling itself a search engine is one: the host name its address gives must belong to the engine
 * and lead back to the same address. Anyone can call their script Googlebot; only Google's addresses are named so.
 */
class SearchEngineProof
{
    /** @param string $engine the engine's name as VisitorAgent reads it, such as Googlebot */
    public function proves(string $address, string $engine): bool
    {
        $suffixes = VisitorAgent::SEARCH_ENGINES[$engine] ?? [];
        $host = $this->hostOf($address);
        if ($host === null) return false;
        $host = strtolower(rtrim($host, '.'));
        $belongs = false;
        foreach ($suffixes as $suffix) {
            if (str_ends_with($host, '.' . $suffix)) $belongs = true;
        }
        if (!$belongs) return false;
        foreach ($this->addressesOf($host, str_contains($address, ':')) as $back) {
            if (@inet_pton($back) === @inet_pton($address)) return true;
        }

        return false;
    }

    /** The host name an address gives, or null. */
    protected function hostOf(string $address): ?string
    {
        $host = @gethostbyaddr($address);

        return is_string($host) && $host !== $address ? $host : null;
    }

    /** @return list<string> the addresses a host name leads to */
    protected function addressesOf(string $host, bool $v6): array
    {
        $records = @dns_get_record($host, $v6 ? DNS_AAAA : DNS_A);
        $addresses = [];
        foreach (is_array($records) ? $records : [] as $record) {
            $found = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            if ($found !== '') $addresses[] = $found;
        }

        return $addresses;
    }
}
