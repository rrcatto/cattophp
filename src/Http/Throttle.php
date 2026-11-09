<?php

declare(strict_types=1);

namespace CattoPHP\Http;

use CattoPHP\Foundation\Duration;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * How often something may be done: at most $limit times in a sliding window, counted for each key (an email address,
 * a network address), with the counts in the installation's cache. Used for anything that sends mail on a visitor's
 * behalf. A key is counted as a hash, so the cache holds no addresses.
 */
final class Throttle
{
    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
    }

    /**
     * Counts one more for every key; false, counting nothing, when any key has reached its limit.
     *
     * @param list<string> $keys
     */
    public function allow(string $action, array $keys, int $limit, Duration $window): bool
    {
        $factory = new RateLimiterFactory([
            'id' => 'cattophp_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($action)),
            'policy' => 'sliding_window',
            'limit' => max(1, $limit),
            'interval' => $window->lengthInSeconds() . ' seconds',
        ], new CacheStorage($this->cache));
        $limiters = [];
        foreach (array_unique($keys) as $key) {
            $limiters[] = $factory->create(hash('sha256', mb_strtolower(trim($key))));
        }
        foreach ($limiters as $limiter) {
            if ($limiter->consume(0)->getRemainingTokens() < 1) {
                return false;
            }
        }
        foreach ($limiters as $limiter) {
            $limiter->consume();
        }

        return true;
    }
}
