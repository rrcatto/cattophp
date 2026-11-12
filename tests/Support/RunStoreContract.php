<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use CattoPHP\Scheduler\RunStore;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;

/** What every store of scheduled runs does, the database's and a file's alike. */
final class RunStoreContract
{
    public static function holds(RunStore $store, string $kind): void
    {
        Assert::assertSame([], $store->all(), $kind . ': nothing has run');
        $start = new DateTimeImmutable('2026-11-12 10:00:00+00:00');
        $store->started('orders', $start);
        $store->succeeded('orders', $start->modify('+2 seconds'), 'Brought up to date.');
        $store->started('orders', $start->modify('+60 seconds'));
        $store->failed('orders', $start->modify('+61 seconds'), 'The mail server did not answer.');
        $store->started('clean_up', $start);
        $all = $store->all();
        Assert::assertSame(['clean_up', 'orders'], array_keys($all), $kind . ': by key');
        $orders = $all['orders'];
        Assert::assertSame(2, $orders['run_count'], $kind . ': counted');
        Assert::assertEquals($start->modify('+60 seconds'), new DateTimeImmutable((string) $orders['last_started_at']), $kind);
        Assert::assertEquals($start->modify('+2 seconds'), new DateTimeImmutable((string) $orders['last_succeeded_at']), $kind . ': a failure keeps the last success');
        Assert::assertEquals($start->modify('+61 seconds'), new DateTimeImmutable((string) $orders['last_failed_at']), $kind);
        Assert::assertEquals($start->modify('+61 seconds'), new DateTimeImmutable((string) $orders['last_finished_at']), $kind);
        Assert::assertSame(['Brought up to date.', 'The mail server did not answer.'], [$orders['last_summary'], $orders['last_error']], $kind);
        $store->failed('clean_up', $start->modify('+1 second'), str_repeat('é', 1200));
        Assert::assertSame(str_repeat('é', 1000), $store->all()['clean_up']['last_error'], $kind . ': a long error is cut to 1000 characters');
        $store->started('video_check', $start);
        $all = $store->all();
        Assert::assertSame([null, null, null, '', '', 1], [$all['video_check']['last_succeeded_at'], $all['video_check']['last_failed_at'], $all['video_check']['last_finished_at'], $all['video_check']['last_summary'], $all['video_check']['last_error'], $all['video_check']['run_count']], $kind . ': started and not yet finished');
    }
}
