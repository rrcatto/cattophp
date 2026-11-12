<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Inbox;

use CattoPHP\Inbox\SpamFilter;
use CattoPHP\Inbox\SpamSigns;
use PHPUnit\Framework\TestCase;

/** The spam check's fixed signs and word filter, ported from cattolms's InboxSpamTest with every case it covers. */
final class InboxSpamTest extends TestCase
{
    public function testAMessageIsSplitIntoItsWordsAndItsLinksDomains(): void
    {
        $words = SpamFilter::words('Get BACKLINKS now: https://rank-booster.example/offer or mail sales@rank-booster.example. Call 0821234567 today!');
        self::assertContains('backlinks', $words);
        self::assertContains('site:rank-booster.example', $words);
        self::assertNotContains('0821234567', $words, 'Numbers alone are no words.');
        self::assertNotContains('or', $words, 'Words of fewer than three letters are left out.');
        self::assertSame(array_values(array_unique($words)), $words, 'Each word once.');
    }

    public function testTheFilterLearnsAndJudges(): void
    {
        self::assertSame(0.5, SpamFilter::probability(['backlinks'], [], 0, 0), 'Untaught, it knows nothing.');
        $counts = ['backlinks' => [9, 0], 'guaranteed' => [8, 1], 'traffic' => [7, 0], 'invoice' => [0, 9], 'course' => [1, 12], 'receipt' => [0, 8]];
        $spam = SpamFilter::probability(['backlinks', 'guaranteed', 'traffic'], $counts, 10, 14);
        $genuine = SpamFilter::probability(['invoice', 'course', 'receipt'], $counts, 10, 14);
        $mixed = SpamFilter::probability(['backlinks', 'invoice'], $counts, 10, 14);
        self::assertGreaterThan(0.9, $spam);
        self::assertLessThan(0.1, $genuine);
        self::assertEqualsWithDelta(0.5, $mixed, 0.3, 'Spammy and genuine words together are unsure, not either.');
        self::assertSame(0.5, SpamFilter::probability(['unseen'], $counts, 10, 14), 'Words it never saw tell it nothing.');
        self::assertSame([0.0, 0.25, 1.0], [SpamFilter::confidence(0, 30), SpamFilter::confidence(5, 30), SpamFilter::confidence(25, 20)], 'It counts more as it learns, fully from 20 of each.');
    }

    public function testTheSignsFindASpammerInWords(): void
    {
        $spam = SpamSigns::judge(['text' => 'Get your website to page one of Google in 7 days! Guaranteed SEO and backlinks. Click here: https://a.example https://b.example https://c.example https://d.example',
            'name' => 'Rank Booster Pro', 'disposable' => true, 'seconds_on_page' => 2, 'datacenter' => true]);
        self::assertSame(100, $spam['score']);
        $words = array_column($spam['signs'], 'words');
        foreach (['throwaway email domain', 'sent 2 seconds after the page opened', 'hosting network', '4 links'] as $sign) self::assertContains($sign, $words);
        self::assertNotEmpty(array_filter($words, static fn(string $w): bool => str_contains($w, 'phrases seen in spam')));

        $genuine = SpamSigns::judge(['text' => 'My receipt for order CL-00011812 shows my old home address. Could you send one with my company\'s address?', 'name' => 'Thandeka Mokoena', 'signed_in' => true, 'seconds_on_page' => 120]);
        self::assertSame(0, $genuine['score'], 'A signed-in account holder writing plainly is genuine.');
        self::assertSame(['signed_in'], array_column($genuine['signs'], 'key'));

        self::assertContains('written in capitals', array_column(SpamSigns::judge(['text' => 'HELLO DEAR I HAVE A VERY IMPORTANT OFFER FOR YOUR COMPANY TODAY', 'name' => 'Maria L.'])['signs'], 'words'));
        self::assertContains('asks to be written to elsewhere', array_column(SpamSigns::judge(['text' => 'Please reply to my private email for the details.', 'name' => 'Maria Lopez'])['signs'], 'words'));
        self::assertContains('a made-up name', array_column(SpamSigns::judge(['text' => 'Hello', 'name' => 'xkcdrt'])['signs'], 'words'));
        self::assertSame(100, SpamSigns::judge(['text' => 'Hello', 'name' => 'Ann Smith', 'blocked_sender' => true])['score'], 'A blocked sender is spam.');
    }
}
