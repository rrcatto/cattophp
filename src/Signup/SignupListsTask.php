<?php

declare(strict_types=1);

namespace CattoPHP\Signup;

use CattoPHP\Network\ReputationLists;
use CattoPHP\Scheduler\ScheduledTask;
use DateTimeImmutable;
use RuntimeException;

/** Every day: fetches the lists the sign-up check and the network lookup read, each when it is due. */
final class SignupListsTask implements ScheduledTask
{
    public function __construct(private readonly ReputationLists $lists)
    {
    }

    public function key(): string
    {
        return 'signup_lists';
    }

    public function label(): string
    {
        return 'Sign-up check lists';
    }

    public function description(): string
    {
        return 'Every day: fetches the Tor exit addresses, and every week the VPN and hosting networks and the throwaway email domains, for the sign-up check.';
    }

    public function interval(): int
    {
        return 86400;
    }

    public function runsAt(): null
    {
        return null;
    }

    public function overdueAfter(): int
    {
        return 3 * 86400;
    }

    public function run(DateTimeImmutable $now): string
    {
        $done = $this->lists->update();
        $failed = array_filter($done, static fn(string $what): bool => str_starts_with($what, 'not fetched'));
        if (count($failed) === count($done)) {
            throw new RuntimeException('No list could be fetched: ' . implode('; ', $failed));
        }

        return implode('. ', array_map(static fn(string $name, string $what): string => ReputationLists::LISTS[$name]['label'] . ': ' . $what, array_keys($done), $done)) . '.';
    }
}
