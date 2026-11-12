<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Search;

use CattoPHP\Activity\Actor;
use CattoPHP\Search\AiCrawlers;
use CattoPHP\Search\SearchSettingGroup;
use CattoPHP\Search\SearchSettings;
use CattoPHP\Tests\Support\Foundations;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** What AI crawlers may do and the verification codes, ported from cattolms's search tests. */
final class SearchSettingsTest extends TestCase
{
    public function testACodeIsTakenBareOrFromItsWholeMetaTag(): void
    {
        self::assertSame('Ab12_cd-34EFgh56ijKL78mnOP90qrSTuv12wxYZ345', SearchSettings::code('  Ab12_cd-34EFgh56ijKL78mnOP90qrSTuv12wxYZ345 ', 'google-site-verification', 'The code'));
        self::assertSame('Ab12_cd-34EFgh56ijKL78mnOP90qrSTuv12wxYZ345', SearchSettings::code('<meta name="google-site-verification" content="Ab12_cd-34EFgh56ijKL78mnOP90qrSTuv12wxYZ345" />', 'google-site-verification', 'The code'));
        self::assertSame('0123456789ABCDEF0123456789ABCDEF', SearchSettings::code("<meta name='msvalidate.01' content='0123456789ABCDEF0123456789ABCDEF'>", 'msvalidate.01', 'The code'));
        self::assertSame('', SearchSettings::code('', 'msvalidate.01', 'The code'), 'Empty leaves it unset.');
        foreach (['<meta name="msvalidate.01" content="0123456789ABCDEF0123456789ABCDEF">' => 'google-site-verification', 'abc def ghi jkl' => 'msvalidate.01', '"><script>' => 'msvalidate.01', '<p>no tag</p>' => 'msvalidate.01', '<meta name="msvalidate.01">' => 'msvalidate.01'] as $typed => $meta) {
            $refused = false;
            try {
                SearchSettings::code($typed, $meta, 'The code');
            } catch (InvalidArgumentException) {
                $refused = true;
            }
            self::assertTrue($refused, 'Refused: ' . $typed);
        }
        $settings = SearchSettings::fixed(['ai_crawlers' => 'search', 'google' => 'Ab12_cd-34EFgh56ijKL78mnOP90qrSTuv12wxYZ345', 'bing' => '']);
        self::assertSame(['google-site-verification' => 'Ab12_cd-34EFgh56ijKL78mnOP90qrSTuv12wxYZ345'], $settings->verificationTags());
        self::assertSame(SearchSettings::SEARCH_ONLY, $settings->aiCrawlers());
        self::assertSame(SearchSettings::ALLOW_ALL, SearchSettings::fixed([])->aiCrawlers(), 'Every AI crawler is let in until it is saved.');
        self::assertSame(['ai_crawlers' => 'all', 'google' => '', 'bing' => ''], SearchSettings::fixed(['ai_crawlers' => 'some', 'google' => 'has spaces in it', 'bing' => 'x'])->saved(), 'What is no longer a choice or a code reads as unset.');
    }

    public function testSavedAsSettingsWithEachChangeLogged(): void
    {
        $site = new Foundations(settingsProviders: [new SearchSettingGroup()]);
        $settings = new SearchSettings($site->settings);
        self::assertSame(['ai_crawlers' => 'all', 'google' => '', 'bing' => ''], $settings->saved());
        $actor = new Actor('7', 'Ada Admin');
        $change = $settings->save(['ai_crawlers' => 'search', 'google' => '<meta name="google-site-verification" content="Ab12_cd-34EFgh56ijKL78mnOP90qrSTuv12wxYZ345" />', 'bing' => ''], $actor);
        self::assertSame(['old' => ['ai_crawlers' => 'all', 'google' => '', 'bing' => ''], 'new' => ['ai_crawlers' => 'search', 'google' => 'Ab12_cd-34EFgh56ijKL78mnOP90qrSTuv12wxYZ345', 'bing' => '']], $change);
        self::assertSame('search', $site->settings->get('SEARCH_AI_CRAWLERS'));
        self::assertSame('Ab12_cd-34EFgh56ijKL78mnOP90qrSTuv12wxYZ345', $site->settings->get('SEARCH_GOOGLE_CODE'));
        $logged = array_map(static fn($entry): string => $entry->action . ' ' . ($entry->details['setting'] ?? ''), $site->activityStore->recent(10));
        self::assertContains('settings.changed SEARCH_AI_CRAWLERS', $logged);
        self::assertContains('settings.changed SEARCH_GOOGLE_CODE', $logged);

        foreach ([['ai_crawlers' => 'some'], ['ai_crawlers' => 'none', 'bing' => 'not a code at all']] as $wrong) {
            try {
                $settings->save($wrong + ['google' => '', 'bing' => ''], $actor);
                self::fail('Refused: ' . json_encode($wrong));
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame('search', $settings->aiCrawlers(), 'Nothing is saved when one value is wrong.');
        $settings->save(['ai_crawlers' => 'all', 'google' => '', 'bing' => ''], $actor);
        self::assertFalse($site->settings->isOverridden('SEARCH_AI_CRAWLERS'), 'Back to the default: no override is kept.');
        $this->expectException(InvalidArgumentException::class);
        SearchSettings::fixed([])->save(['ai_crawlers' => 'all']);
    }

    public function testEachChoiceLetsInTheKindsItSays(): void
    {
        foreach ([AiCrawlers::TRAINING, AiCrawlers::SEARCH, AiCrawlers::USER] as $kind) {
            self::assertTrue(AiCrawlers::allowed($kind, SearchSettings::ALLOW_ALL));
            self::assertFalse(AiCrawlers::allowed($kind, SearchSettings::BLOCK_ALL));
        }
        self::assertFalse(AiCrawlers::allowed(AiCrawlers::TRAINING, SearchSettings::SEARCH_ONLY));
        self::assertTrue(AiCrawlers::allowed(AiCrawlers::SEARCH, SearchSettings::SEARCH_ONLY));
        self::assertTrue(AiCrawlers::allowed(AiCrawlers::USER, SearchSettings::SEARCH_ONLY));
        $all = AiCrawlers::all();
        foreach (['GPTBot' => AiCrawlers::TRAINING, 'OAI-SearchBot' => AiCrawlers::SEARCH, 'ChatGPT-User' => AiCrawlers::USER, 'ClaudeBot' => AiCrawlers::TRAINING, 'Claude-SearchBot' => AiCrawlers::SEARCH, 'Claude-User' => AiCrawlers::USER,
            'Google-Extended' => AiCrawlers::SEARCH, 'PerplexityBot' => AiCrawlers::SEARCH, 'Applebot-Extended' => AiCrawlers::TRAINING, 'meta-externalagent' => AiCrawlers::TRAINING, 'CCBot' => AiCrawlers::TRAINING] as $token => $kind) {
            self::assertSame($kind, $all[$token]['kind'] ?? null, $token);
        }
        foreach (['Googlebot', 'Bingbot', 'Applebot', 'facebookexternalhit', 'Twitterbot', 'LinkedInBot'] as $never) {
            self::assertArrayNotHasKey($never, $all, $never . ' is a search engine or a link preview, never kept out as AI.');
        }
        self::assertSame(count($all), array_sum(AiCrawlers::counts()));
    }
}
