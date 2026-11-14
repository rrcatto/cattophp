<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use CattoPHP\Marketing\MarketingProject;
use CattoPHP\Marketing\Offer;
use CattoPHP\Tests\Kernel\App\ExampleMarketingProject;

/**
 * The example shop (ExampleMarketingProject) as a test changes it: an offer taken off the site's public pages, one whose
 * code is changed, one removed, and a draft product added that visitors may not see.
 */
final class ChangingShop implements MarketingProject
{
    /** @var array<string,true> */
    private array $hidden = [];

    /** @var array<string,true> */
    private array $removed = [];

    /** @var array<string,string> */
    private array $renamed = [];

    private readonly ExampleMarketingProject $shop;

    public function __construct()
    {
        $this->shop = new ExampleMarketingProject();
    }

    /** Visitors may no longer see an offer (its key, type:reference). */
    public function hide(string $key): void
    {
        $this->hidden[$key] = true;
    }

    public function show(string $key): void
    {
        unset($this->hidden[$key]);
    }

    public function remove(string $key): void
    {
        $this->removed[$key] = true;
    }

    /** An offer's code changed: it is known by its new one alone. */
    public function rename(string $key, string $reference): void
    {
        $this->renamed[$key] = $reference;
    }

    public function offersHeading(): string
    {
        return $this->shop->offersHeading();
    }

    public function offerKinds(): array
    {
        return $this->shop->offerKinds();
    }

    public function offers(): array
    {
        $offers = [];
        foreach ([...$this->shop->offers(), new Offer('product', 'pot-02', 'Teapot', '/shop/pot-02', 'R400', '', false, false, 'blue-enamel-teapot')] as $offer) {
            $key = $offer->key();
            if (isset($this->removed[$key])) {
                continue;
            }
            $reference = $this->renamed[$key] ?? $offer->reference;
            $offers[] = new Offer($offer->type, $reference, $offer->label, $offer->href, $offer->price, $offer->wasPrice, $offer->available, $offer->public && !isset($this->hidden[$key]), $offer->slug);
        }

        return $offers;
    }

    public function offer(string $type, string $reference): ?Offer
    {
        foreach ($this->offers() as $offer) {
            if ($offer->type === $type && $offer->reference === $reference) {
                return $offer;
            }
        }

        return null;
    }

    public function account(string $id): ?array
    {
        return $this->shop->account($id);
    }

    public function organisation(string $reference): ?array
    {
        return $this->shop->organisation($reference);
    }

    public function accountsMatching(string $search, int $limit = 50): array
    {
        return $this->shop->accountsMatching($search, $limit);
    }

    public function publicationSource(string $type, string $reference): ?array
    {
        return $this->shop->publicationSource($type, $reference);
    }

    public function saleHref(string $reference): string
    {
        return $this->shop->saleHref($reference);
    }

    public function promotions(): array
    {
        return $this->shop->promotions();
    }

    public function promotionHref(string $reference): string
    {
        return $this->shop->promotionHref($reference);
    }

    public function money(int $minor): string
    {
        return $this->shop->money($minor);
    }

    public function currency(): string
    {
        return $this->shop->currency();
    }
}
