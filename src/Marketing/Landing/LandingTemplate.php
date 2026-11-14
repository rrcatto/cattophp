<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

/**
 * A template a landing page starts from: built in (a provider's, by its key) or saved by someone (by "saved:" and its
 * reference). Its name and note for its feature card, which pages it suits (campaign, offer or both) and its sections,
 * each a kind with its width, when it is shown, whether it is shown and its data, copy included and offers left out.
 */
final class LandingTemplate
{
    public const SUITS = ['campaign', 'offer', 'both'];

    /** @param list<array{kind:string,width?:string,shown_when?:string,enabled?:bool,data?:array<string,mixed>}> $sections */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $note,
        public readonly string $suits,
        public readonly array $sections,
        public readonly bool $builtIn = true,
        public readonly ?string $reference = null,
        public readonly ?string $madeBy = null,
    ) {
        if (!in_array($suits, self::SUITS, true)) {
            throw new \InvalidArgumentException('A template suits campaign pages, offer pages or both.');
        }
    }

    public function suitsPage(LandingContext $page): bool
    {
        return $this->suits === 'both' || $this->suits === $page->kind;
    }
}
