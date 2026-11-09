<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

use DateTimeImmutable;

/** Where sign-in links are kept, by the hash of each. */
interface SignInLinkStore
{
    public function add(string $hash, SignInLink $link, DateTimeImmutable $at): void;

    /** The link if it can still be used; looking does not use it. */
    public function usable(string $hash, DateTimeImmutable $now): ?SignInLink;

    /** Uses the link up and returns it; of two attempts at the same moment only one gets it. */
    public function claim(string $hash, DateTimeImmutable $now): ?SignInLink;

    /** Removes links that have run out or been used. */
    public function forgetOld(DateTimeImmutable $now): void;
}
