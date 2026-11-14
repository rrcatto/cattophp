<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

/**
 * The project's part when it gives none: no offers, no promotions, nobody named, and money as a plain number with two
 * decimals, so the Campaign Marketing Manager works on its own.
 */
final class NoProject implements MarketingProject
{
    public function offersHeading(): string
    {
        return 'Offers';
    }

    public function offerKinds(): array
    {
        return [];
    }

    public function offers(): array
    {
        return [];
    }

    public function offer(string $type, string $reference): ?Offer
    {
        return null;
    }

    public function account(string $id): ?array
    {
        return null;
    }

    public function organisation(string $reference): ?array
    {
        return null;
    }

    public function accountsMatching(string $search, int $limit = 50): array
    {
        return [];
    }

    public function publicationSource(string $type, string $reference): ?array
    {
        return null;
    }

    public function saleHref(string $reference): ?string
    {
        return null;
    }

    public function promotions(): array
    {
        return [];
    }

    public function promotionHref(string $reference): ?string
    {
        return null;
    }

    public function money(int $minor): string
    {
        return ($minor < 0 ? '-' : '') . number_format(abs($minor) / 100, 2, '.', ',');
    }

    public function currency(): string
    {
        return '';
    }
}
