<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

/**
 * Every kind of section a landing page can hold: CattoPHP's own and the project's (tag cattophp.marketing_landing_section),
 * by key. Two kinds with the same key, or a key that is not small letters, digits and underscores, are refused here, the
 * first time the kinds are read, naming both classes.
 */
final class SectionKinds
{
    public const KEY = '/^[a-z][a-z0-9_]{0,39}$/';

    /** @var array<string,SectionKind> */
    private array $kinds = [];

    /** @param iterable<SectionKind> $kinds */
    public function __construct(iterable $kinds)
    {
        foreach ($kinds as $kind) {
            $key = $kind->key();
            if (preg_match(self::KEY, $key) !== 1) {
                throw new \LogicException(sprintf('The landing section kind %s has the key "%s": a key is small letters, digits and underscores, up to 40.', $kind::class, $key));
            }
            if (isset($this->kinds[$key])) {
                throw new \LogicException(sprintf('Two landing section kinds have the key "%s": %s and %s. Give one of them another key.', $key, $this->kinds[$key]::class, $kind::class));
            }
            $this->kinds[$key] = $kind;
        }
    }

    public function get(string $key): ?SectionKind
    {
        return $this->kinds[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->kinds[$key]);
    }

    /** @return array<string,SectionKind> every kind, by key, CattoPHP's first */
    public function all(): array
    {
        return $this->kinds;
    }

    /** @return array<string,SectionKind> the kinds that can be on a page */
    public function applying(LandingContext $page): array
    {
        return array_filter($this->kinds, static fn(SectionKind $kind): bool => $kind->appliesTo($page));
    }
}
