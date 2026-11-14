<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel\App;

use CattoPHP\Marketing\MarketingProject;
use CattoPHP\Marketing\Offer;

/** A project's part in the Campaign Marketing Manager, as a small shop would give it: two products and a bundle, one promotion, money in rand. */
final class ExampleMarketingProject implements MarketingProject
{
    public function offersHeading(): string
    {
        return 'Products and bundles';
    }

    public function offerKinds(): array
    {
        return ['product' => 'Product', 'bundle' => 'Bundle'];
    }

    public function offers(): array
    {
        return [
            new Offer('product', 'tea-100', 'Rooibos tea, 100 bags', '/shop/tea-100', 'R95', 'R120'),
            new Offer('product', 'mug-01', 'Enamel mug', '/shop/mug-01', 'R150'),
            new Offer('bundle', 'gift-box', 'Gift box', '/shop/gift-box', 'R320', '', false),
        ];
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
        return $id === '1' ? ['label' => 'Ayanda Khumalo', 'reference' => 'ACC-0001', 'href' => '/admin/people/ACC-0001'] : null;
    }

    public function organisation(string $reference): ?array
    {
        return $reference === 'ORG-0001' ? ['label' => 'Fynbos Traders', 'reference' => 'ORG-0001', 'href' => '/admin/companies/ORG-0001'] : null;
    }

    public function accountsMatching(string $search, int $limit = 50): array
    {
        $search = mb_strtolower(trim($search));
        if ($search === '' || $limit < 1) {
            return [];
        }
        // The shop's one person, found by name, email address or account reference.
        foreach (['ayanda khumalo', 'ayanda@example.com', 'acc-0001'] as $known) {
            if (str_contains($known, $search)) {
                return ['1'];
            }
        }

        return [];
    }

    public function publicationSource(string $type, string $reference): ?array
    {
        return $type === 'mailing' ? ['label' => 'Message ' . $reference, 'href' => '/admin/mailing/messages/' . rawurlencode($reference)] : null;
    }

    public function saleHref(string $reference): string
    {
        return '/admin/orders/' . rawurlencode($reference);
    }

    public function promotions(): array
    {
        return ['SPRING10' => 'Spring, 10% off'];
    }

    public function promotionHref(string $reference): string
    {
        return '/admin/promotions/' . rawurlencode($reference);
    }

    public function money(int $minor): string
    {
        return 'R' . number_format(intdiv($minor, 100)) . ($minor % 100 === 0 ? '' : sprintf('.%02d', $minor % 100));
    }

    public function currency(): string
    {
        return 'R';
    }
}
