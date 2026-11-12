<?php

declare(strict_types=1);

namespace CattoPHP\Inbox;

/**
 * The project's own documents a reply to an account may attach, such as its invoices and receipts (`inbox.documents`).
 * Without one, a reply carries only the files added to it.
 */
interface InboxDocuments
{
    /**
     * The account's documents, newest first.
     *
     * @return list<array{id:int,number:string,kind:string,label:string}>
     */
    public function of(string $account): array;

    /**
     * A document as a reply attaches it, or null when it cannot be had.
     *
     * @return array{0:string,1:string,2:string}|null [body, name, type]
     */
    public function attachment(int $id): ?array;
}
