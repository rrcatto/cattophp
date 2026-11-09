<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use CattoPHP\Sessions\SessionStore;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;

/** What every store of sign-ins does, the database's, a file's and memory's alike. */
final class SessionStoreContract
{
    public static function holds(SessionStore $store, string $kind): void
    {
        $now = new DateTimeImmutable('2026-11-09 10:00:00+00:00');
        $day = $now->modify('+1 day');
        $first = $store->start('hash-a', 'public-a', '7', 'owner@example.com', 'place-a', 'Firefox', $now, $day);
        $second = $store->start('hash-b', 'public-b', '7', 'owner@example.com', 'place-b', 'Chrome', $now->modify('+1 minute'), $day);
        $store->start('hash-c', 'public-c', '8', null, null, null, $now, $day);

        $active = $store->active('hash-a', $now);
        Assert::assertNotNull($active, $kind);
        Assert::assertSame([$first->id, 'public-a', '7', 'owner@example.com', 'place-a', 'Firefox'], [$active->id, $active->publicId, $active->accountId, $active->email, $active->requestedFrom, $active->userAgent], $kind);
        Assert::assertNull($store->active('hash-a', $day), $kind . ': run out');
        Assert::assertSame(['public-b', 'public-a'], array_map(static fn ($s): string => $s->publicId, $store->activeFor('7', $now)), $kind . ': newest first');

        $later = $now->modify('+12 hours');
        $store->touch($first->id, $later, $later->modify('+1 day'));
        Assert::assertSame($later->getTimestamp(), $store->active('hash-a', $day)?->lastSeenAt->getTimestamp(), $kind . ': seen, and lasting longer');

        Assert::assertFalse($store->end('8', 'public-a', $now), $kind . ': never another account\'s');
        Assert::assertTrue($store->end('7', 'public-a', $now), $kind);
        Assert::assertFalse($store->end('7', 'public-a', $now), $kind . ': once');
        Assert::assertNull($store->active('hash-a', $now), $kind);
        $store->endByHash('hash-c', $now);
        Assert::assertNull($store->active('hash-c', $now), $kind . ': signed out');
        Assert::assertSame(1, $store->endAllFor('7', $now), $kind . ': the one still going');
        Assert::assertSame([], $store->activeFor('7', $now), $kind);
        Assert::assertSame($second->publicId, 'public-b');

        $store->start('hash-d', 'public-d', '9', null, null, null, $day, $day->modify('+1 day'));
        Assert::assertSame(3, $store->forgetOld($day), $kind . ': the ended go');
        Assert::assertNotNull($store->active('hash-d', $day), $kind . ': the one going stays');
    }
}
