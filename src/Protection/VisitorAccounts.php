<?php

declare(strict_types=1);

namespace CattoPHP\Protection;

/**
 * Who is making the request the Site Log writes down: the signed-in account's id, and whether it may see site
 * protection (an administrator, whose addresses the rules spare). A project with its own sign-in gives its own
 * (`protection.visitors`); CattoPHP's sign-in has one.
 */
interface VisitorAccounts
{
    /** @return array{id:string,administrator:bool}|null null for someone not signed in */
    public function current(): ?array;
}
