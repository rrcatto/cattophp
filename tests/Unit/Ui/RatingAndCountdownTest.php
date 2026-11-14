<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Ui;

use CattoPHP\Tests\Support\HtmlQuery;
use CattoPHP\Tests\Support\UiHarness;
use CattoPHP\Ui\Contract\SourceAudit;
use CattoPHP\Ui\Contract\TemplateContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The rating and the countdown: what each says to the eye and to a screen reader, what they refuse, what a page without
 * JavaScript sees, and that no page draws its own copy of either.
 */
final class RatingAndCountdownTest extends TestCase
{
    /** 14 November 2026, 12:00 in Johannesburg. */
    private const NOON = 1794650400;

    private string $zone;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('Africa/Johannesburg');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    private static function nodes(HtmlQuery $page, string $expression): int
    {
        return $page->query($expression)->length;
    }

    /** @return list<string> each node's words, spaces run together */
    private static function texts(HtmlQuery $page, string $expression): array
    {
        $texts = [];
        foreach ($page->query($expression) as $node) {
            $texts[] = trim((string) preg_replace('/\s+/u', ' ', (string) $node->textContent));
        }

        return $texts;
    }

    private static function text(HtmlQuery $page, string $expression): string
    {
        return self::texts($page, $expression)[0] ?? '';
    }

    /** @param array<string,mixed> $properties */
    private static function refused(string $component, array $properties, string $message): void
    {
        try {
            UiHarness::components()->properties($component, $properties);
            self::fail($component . ' accepted ' . json_encode($properties));
        } catch (InvalidArgumentException $refused) {
            self::assertStringContainsString($message, $refused->getMessage());
        }
    }

    public function testARatingShowsItsStarsAndFiguresAndSaysTheSameInOneSentence(): void
    {
        $html = UiHarness::render('data.rating', ['average' => 4.36, 'count' => 1234]);
        $page = UiHarness::xpath($html);
        self::assertSame(1, self::nodes($page, '//p[@class="cl-rating-summary"]'));
        self::assertSame(['★', '★', '★', '★', '☆'], self::texts($page, '//span[@class="cl-rating-stars"][@aria-hidden="true"]/span'), 'The mean rounded: four whole stars filled.');
        self::assertSame(4, self::nodes($page, '//span[@class="cl-rating-stars"]/span[@class="is-filled"]'));
        self::assertSame('Rated 4.4 out of 5 from 1,234 reviews', self::text($page, '//span[@class="visually-hidden"]'));
        self::assertSame('4.4 · 1,234 reviews', self::text($page, '//span[@class="cl-rating-figures"][@aria-hidden="true"]'));
        self::assertSame('Rated 5.0 out of 5 from 1 review', self::text(UiHarness::xpath(UiHarness::render('data.rating', ['average' => 5, 'count' => 1])), '//span[@class="visually-hidden"]'), 'One review is a review.');
        self::assertStringContainsString('class="cl-rating-summary is-compact"', UiHarness::render('data.rating', ['average' => 3.5, 'count' => 8, 'compact' => true]));
        self::assertStringNotContainsString('style=', $html, 'No inline style under the strict policy.');
    }

    public function testARatingWithNoReviewsSaysSoOrDrawsNothing(): void
    {
        $page = UiHarness::xpath(UiHarness::render('data.rating', ['count' => 0, 'average' => 4.0]));
        self::assertSame('No reviews yet', self::text($page, '//p[@class="cl-rating-summary"]/span[@class="cl-rating-none"]'));
        self::assertSame(0, self::nodes($page, '//span[@class="cl-rating-stars"]'), 'No row of empty stars, which would read as a rating of nothing.');
        self::assertStringContainsString('Be the first &lt;to&gt; review it', UiHarness::render('data.rating', ['count' => 0, 'empty' => 'Be the first <to> review it']));
        self::assertSame('', trim(UiHarness::render('data.rating', ['count' => 0, 'empty' => ''])), 'With empty words a page shows nothing until there are reviews.');
    }

    public function testARatingRefusesWhatIsNotARating(): void
    {
        self::refused('data.rating', [], 'Required UI property: data.rating.count');
        self::refused('data.rating', ['count' => -1], 'A rating\'s count is a whole number of nothing or more.');
        self::refused('data.rating', ['count' => 2.5], 'A rating\'s count is a whole number');
        self::refused('data.rating', ['count' => 3, 'average' => 5.1], 'A rating\'s average is a number from 0 to 5.');
        self::refused('data.rating', ['count' => 3, 'average' => '4'], 'A rating\'s average is a number from 0 to 5.');
        self::refused('data.rating', ['count' => 3, 'average' => NAN], 'A rating\'s average is a number from 0 to 5.');
        self::refused('data.rating', ['count' => 3, 'compact' => 'yes'], 'compact is true or false');
    }

    public function testACountdownDrawsTheTimeLeftForTheScriptAndTheMomentForEveryone(): void
    {
        $html = UiHarness::render('feedback.countdown', ['ends_at' => self::NOON + 2 * 86400 + 3 * 3600 + 4 * 60 + 5, 'now' => self::NOON, 'words' => 'Offer <ends> in']);
        $page = UiHarness::xpath($html);
        self::assertSame(1, self::nodes($page, '//div[@class="cl-ui-countdown"][@role="timer"][@aria-live="off"][@data-cl-countdown="' . (self::NOON + 183845) . '"][@data-cl-now="' . self::NOON . '"]'));
        // Without JavaScript the count would stand still, so it waits hidden for the script; the moment always shows.
        self::assertSame(1, self::nodes($page, '//span[@class="cl-ui-countdown-count"][@data-cl-countdown-count][@hidden]'));
        self::assertSame('Until 16 November 2026 at 15:04 SAST', self::text($page, '//span[@class="cl-ui-countdown-until"]'));
        self::assertSame(1, self::nodes($page, '//span[@class="cl-ui-countdown-until"]/time[@datetime="2026-11-16T15:04:05+02:00"]'));
        self::assertStringContainsString('Offer &lt;ends&gt; in', $html, 'The words are escaped.');
        self::assertSame(['week', 'day', 'hour', 'minute', 'second'], self::texts($page, '//span[@class="cl-ui-countdown-unit"]/@data-unit'), 'Every unit is drawn, for the script to show as it is needed.');
        self::assertSame(['week'], self::texts($page, '//span[@class="cl-ui-countdown-unit"][@hidden]/@data-unit'), 'From the largest that is not nothing.');
        self::assertSame(['0 weeks', '2 days', '3 hours', '4 minutes', '5 seconds'], self::texts($page, '//span[@class="cl-ui-countdown-unit"]'));
        self::assertSame(['day', 'days'], [self::text($page, '//span[@data-unit="day"]/span/@data-one'), self::text($page, '//span[@data-unit="day"]/span/@data-many')]);
        self::assertStringNotContainsString('style=', $html, 'No inline style under the strict policy.');
    }

    public function testACountdownCanStartAtDaysAndNamesOneUnitInTheSingular(): void
    {
        $page = UiHarness::xpath(UiHarness::render('feedback.countdown', ['ends_at' => self::NOON + 9 * 86400 + 3600 + 1, 'now' => self::NOON, 'largest' => 'day', 'words' => '', 'until' => 'Ends']));
        self::assertSame(['9 days', '1 hour', '0 minutes', '1 second'], self::texts($page, '//span[@class="cl-ui-countdown-unit"]'));
        self::assertSame(0, self::nodes($page, '//span[@class="cl-ui-countdown-words"]'), 'No words, no empty element for them.');
        self::assertSame('Ends 23 November 2026 at 13:00 SAST', self::text($page, '//span[@class="cl-ui-countdown-until"]'));
        self::assertSame(['second'], self::texts(UiHarness::xpath(UiHarness::render('feedback.countdown', ['ends_at' => self::NOON + 59, 'now' => self::NOON])), '//span[@class="cl-ui-countdown-unit"][not(@hidden)]/@data-unit'), 'The seconds always show.');
    }

    public function testACountdownToAMomentPassedDrawsNothingAndTheServersClockIsTheDefault(): void
    {
        self::assertSame('', trim(UiHarness::render('feedback.countdown', ['ends_at' => self::NOON, 'now' => self::NOON])), 'At zero it is gone.');
        self::assertSame('', trim(UiHarness::render('feedback.countdown', ['ends_at' => self::NOON - 60, 'now' => self::NOON])));
        $values = UiHarness::components()->properties('feedback.countdown', ['ends_at' => time() + 3600]);
        self::assertFalse($values['ended']);
        self::assertEqualsWithDelta(time(), $values['now'], 2, 'Given no now, the server\'s clock now.');
    }

    public function testACountdownRefusesWhatIsNotAMoment(): void
    {
        self::refused('feedback.countdown', [], 'Required UI property: feedback.countdown.ends_at');
        self::refused('feedback.countdown', ['ends_at' => '2026-11-30T18:00'], 'A countdown\'s ends_at is a moment as a Unix time');
        self::refused('feedback.countdown', ['ends_at' => self::NOON, 'now' => -1], 'A countdown\'s ends_at is a moment as a Unix time');
        self::refused('feedback.countdown', ['ends_at' => self::NOON, 'largest' => 'month'], 'Invalid semantic UI value: feedback.countdown.largest');
        self::refused('feedback.countdown', ['ends_at' => self::NOON, 'words' => 3], 'A countdown\'s words and until are words.');
    }

    public function testNoPageDrawsItsOwnRatingOrCountdownAndTheScriptKeepsTheCountTicking(): void
    {
        self::assertSame([], TemplateContract::duplicates(TemplateContract::cattophpTemplates()));
        self::assertSame([
            'page.html.twig writes cl-rating-summary, which @CattoPHP/ui/data/rating.html.twig owns: draw it with its component instead.',
            'page.html.twig writes cl-ui-countdown, which @CattoPHP/ui/feedback/countdown.html.twig owns: draw it with its component instead.',
        ], TemplateContract::duplicates(['page.html.twig' => '<p class="cl-rating-summary"></p><div class="cl-ui-countdown"></div>']));
        self::assertSame(['timer.html.twig draws what @CattoPHP/ui/feedback/countdown.html.twig draws: use its component instead.'], TemplateContract::duplicates(['timer.html.twig' => '<div role="timer">2 days</div>']));

        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/css/cattophp.css');
        self::assertStringContainsString('.cl-ui-countdown[hidden],.cl-ui-countdown-count[hidden],.cl-ui-countdown-unit[hidden],[data-cl-countdown-scope][hidden]{display:none}', $css, 'The hidden attribute wins over the flex layout.');
        self::assertStringContainsString('.cl-rating-stars>span:not(.is-filled){opacity:.55}', $css);

        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/public/js/cattophp.js');
        foreach (["querySelectorAll('[data-cl-countdown]')", "timer.closest('[data-cl-countdown-scope]')", "timer.querySelector('[data-cl-countdown-count]')", 'Number(timer.dataset.clNow) - now'] as $part) {
            self::assertStringContainsString($part, $script);
        }
        self::assertSame([], SourceAudit::javascript($script, 'cattophp.js'), 'The script draws no component markup.');
    }
}
