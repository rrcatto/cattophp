<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use CattoPHP\Links\LinkStore;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;

/** What every link store does, the database's, a file's and memory's alike. */
final class LinkStoreContract
{
    public static function holds(LinkStore $store, string $kind): void
    {
        $now = new DateTimeImmutable('2026-11-09 10:00:00+00:00');
        $later = $now->modify('+30 minutes');
        $signIn = $store->add('hash-sign-in', 'sign_in', 'owner@example.com', '7', ['return_path' => '/back'], 'place-a', $now, $later);
        $register = $store->add('hash-register', 'registration', 'new@example.com', null, ['form' => ['name' => 'Nomsa']], 'place-a', $now, $later);
        Assert::assertNotSame($signIn, $register, $kind . ': each its own number');

        $link = $store->usable('hash-sign-in', 'sign_in', $now, true);
        Assert::assertNotNull($link, $kind);
        Assert::assertSame(['hash-sign-in', 'sign_in', 'owner@example.com', '7', ['return_path' => '/back'], 'place-a'], [$link->hash, $link->purpose, $link->email, $link->accountId, $link->context, $link->requestedFrom], $kind);
        Assert::assertNull($store->usable('hash-sign-in', 'registration', $now), $kind . ': a link is only for its purpose');
        Assert::assertNull($store->usable('hash-register', 'registration', $later), $kind . ': run out');
        Assert::assertNull($store->usableById($register, 'registration', $now)?->accountId, $kind . ': a registration has no account yet');

        $store->changeContext($register, ['form' => ['name' => 'Nomsa'], 'step' => 2]);
        Assert::assertSame(2, $store->usableById($register, 'registration', $now)?->context['step'], $kind);
        Assert::assertTrue($store->use($signIn, $now), $kind);
        Assert::assertFalse($store->use($signIn, $now), $kind . ': once only, even for two attempts at the same moment');
        Assert::assertNull($store->usable('hash-sign-in', 'sign_in', $now), $kind);
        Assert::assertNotNull($store->find('hash-sign-in', 'sign_in')?->usedAt, $kind . ': found used');

        Assert::assertSame(1, $store->countForEmail('new@example.com', $now->modify('-1 minute')), $kind);
        Assert::assertSame(2, $store->countFrom('place-a', $now->modify('-1 minute')), $kind);
        Assert::assertSame(1, $store->countFrom('place-a', $now->modify('-1 minute'), ['registration']), $kind . ': leaving a purpose out');
        $store->finishOpen('new@example.com', 'registration', $now);
        Assert::assertNull($store->usableById($register, 'registration', $now), $kind . ': a new request replaces it');
        Assert::assertSame(1, $store->countForEmail('new@example.com', $now->modify('-1 minute')), $kind . ': and it still counts');

        $connect = $store->add('hash-connect', 'connect', null, '7', [], null, $now, $later);
        $store->forgetOpen('7', 'connect');
        Assert::assertNull($store->usableById($connect, 'connect', $now), $kind);
        $unsent = $store->add('hash-unsent', 'sign_in', 'owner@example.com', '7', [], null, $now, $later);
        $store->forget('hash-unsent');
        Assert::assertNull($store->usableById($unsent, 'sign_in', $now), $kind . ': an email not sent leaves no link');

        $store->add('hash-fresh', 'sign_in', 'owner@example.com', '7', [], null, $later, $later->modify('+30 minutes'));
        Assert::assertSame(2, $store->forgetOld($later), $kind . ': the used and the run-out go');
        Assert::assertNotNull($store->find('hash-fresh', 'sign_in'), $kind . ': the fresh one stays');
    }
}
