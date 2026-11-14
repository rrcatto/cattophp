<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

/**
 * What the Campaign Marketing Manager asks of the project it runs in: what the site sells (its offers) and what it calls
 * them, how it names a person, where an order and a promotion are seen, and how it writes money. A project gives one
 * service implementing this (config `cattophp.marketing.project`); without one NoProject answers nothing and the module
 * still works. The module never reads a project's records: the project reports what happens by calling the module.
 */
interface MarketingProject
{
    /** What the site's offers are called as a heading, such as "Products and services". */
    public function offersHeading(): string;

    /** @return array<string,string> each kind of offer the site has, by its type, and its word, such as 'product' => 'Product' */
    public function offerKinds(): array;

    /** @return list<Offer> what a campaign may promote now */
    public function offers(): array;

    /** One offer by its type and reference, or null when the site has none such. */
    public function offer(string $type, string $reference): ?Offer;

    /**
     * A person as the project names them, by their account id, or null when the project does not know the account.
     *
     * @return array{label:string,reference:string,href:?string}|null
     */
    public function account(string $id): ?array;

    /**
     * A buying organisation (a company) as the project names it, by the reference a sale gives it, or null when the
     * project does not know it.
     *
     * @return array{label:string,reference:string,href:?string}|null
     */
    public function organisation(string $reference): ?array;

    /**
     * The accounts whose name, email address or account reference matches a search, as the project's own search of its
     * people finds them, by their account ids, at most $limit of them: how the Visitors and Sales pages find a person
     * without the module ever holding a name. An empty search matches nobody.
     *
     * @return list<string>
     */
    public function accountsMatching(string $search, int $limit = 50): array;

    /**
     * What a publication was written in, when the project made it from one of its own records (a mailing message: its
     * source type and reference), with that record's name and page, or null.
     *
     * @return array{label:string,href:?string}|null
     */
    public function publicationSource(string $type, string $reference): ?array;

    /** The page of an order by its reference, or null. */
    public function saleHref(string $reference): ?string;

    /** @return array<string,string> the promotions (discount codes) a campaign may carry, by reference, and their words */
    public function promotions(): array;

    /** The page of a promotion by its reference, or null. */
    public function promotionHref(string $reference): ?string;

    /** An amount in whole minor units as the site writes it, such as "R1,200". */
    public function money(int $minor): string;

    /** The currency's sign or code, such as "R". */
    public function currency(): string;
}
