<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

/**
 * Every source a landing page's pictures come from (tag cattophp.marketing_landing_picture_source), by key; two with the
 * same key are refused. It answers whether a picture a section names is still there and finds it for the page.
 */
final class PictureSources
{
    /** @var array<string,PictureSource> */
    private array $sources = [];

    /** @param iterable<PictureSource> $sources */
    public function __construct(iterable $sources)
    {
        foreach ($sources as $source) {
            if (isset($this->sources[$source->key()])) {
                throw new \LogicException(sprintf('Two landing picture sources have the key "%s": %s and %s.', $source->key(), $this->sources[$source->key()]::class, $source::class));
            }
            $this->sources[$source->key()] = $source;
        }
    }

    public function get(string $key): ?PictureSource
    {
        return $this->sources[$key] ?? null;
    }

    /** @return array<string,PictureSource> */
    public function all(): array
    {
        return $this->sources;
    }

    /** @return array<string,PictureSource> those a page may take pictures from */
    public function applying(LandingContext $page): array
    {
        return array_filter($this->sources, static fn(PictureSource $source): bool => $source->appliesTo($page));
    }

    /** @param array{source:string,ref:string} $picture */
    public function exists(array $picture, LandingContext $page): bool
    {
        return $this->get($picture['source'])?->exists($picture['ref'], $page) ?? false;
    }

    /** @param array{source:string,ref:string} $picture */
    public function resolve(array $picture, LandingContext $page): ?ResolvedPicture
    {
        return $this->get($picture['source'])?->resolve($picture['ref'], $page);
    }

    /** The source's name, for a message, or the key when the source is gone. */
    public function label(string $key): string
    {
        return $this->get($key)?->label() ?? $key;
    }
}
