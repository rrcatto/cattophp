<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use CattoPHP\Setup\SetupMark;
use CattoPHP\Setup\SetupMarkStore;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;

/** What every store of setup marks does. */
final class SetupMarkStoreContract
{
    public static function holds(SetupMarkStore $store, string $kind): void
    {
        Assert::assertSame([], $store->all(), $kind . ': nothing is marked');
        $at = new DateTimeImmutable('2026-11-12 14:00:00+00:00');
        $store->save('theme', new SetupMark(SetupMark::DONE, $at, '7', 'Saharan Sunset chosen.'));
        $store->save('mail', new SetupMark(SetupMark::SKIPPED, $at->modify('+1 minute')));
        $all = $store->all();
        Assert::assertSame(['mail', 'theme'], array_keys($all), $kind . ': by step');
        Assert::assertSame([SetupMark::DONE, '7', 'Saharan Sunset chosen.'], [$all['theme']->state, $all['theme']->by, $all['theme']->note], $kind);
        Assert::assertEquals($at, $all['theme']->at, $kind);
        Assert::assertSame([SetupMark::SKIPPED, null, ''], [$all['mail']->state, $all['mail']->by, $all['mail']->note], $kind);
        $store->save('mail', new SetupMark(SetupMark::DONE, $at->modify('+2 minutes'), '8', str_repeat('é', 1200)));
        $mail = $store->all()['mail'];
        Assert::assertSame([SetupMark::DONE, '8'], [$mail->state, $mail->by], $kind . ': marked again');
        Assert::assertSame(str_repeat('é', 1000), $mail->note, $kind . ': a long note is cut to 1000 characters');
        $store->remove('theme');
        $store->remove('nonsense');
        Assert::assertSame(['mail'], array_keys($store->all()), $kind . ': a mark taken off');
    }
}
