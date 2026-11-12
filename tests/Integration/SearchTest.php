<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Activity\Actor;
use CattoPHP\Search\PageHead;
use CattoPHP\Search\PageOverrides;
use CattoPHP\Search\PublicPage;
use CattoPHP\Search\PublicPageProvider;
use CattoPHP\Search\PublicPages;
use CattoPHP\Settings\EnvironmentValues;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use InvalidArgumentException;

/** An administrator's own words for the public pages, kept in the database, ported from cattolms's search metadata tests. */
final class SearchTest extends DatabaseTestCase
{
    private Foundations $site;
    private PublicPages $pages;
    private PageOverrides $overrides;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = new Foundations();
        $this->pages = new PublicPages([new class implements PublicPageProvider {
            public function pages(): iterable
            {
                yield new PublicPage('home', 'Home page', '/', 'Gardens designed by hand.', 'Example Gardens');
                yield new PublicPage('guides', 'Guides', '/guides', 'Every guide on the site.', 'Guides');
            }
        }]);
        $this->overrides = new PageOverrides($this->connection, $this->pages, $this->site->activity);
    }

    public function testAnAdministratorsOwnWordsAreWhatSearchEnginesAreGiven(): void
    {
        self::assertSame(['home' => ['title' => '', 'description' => ''], 'guides' => ['title' => '', 'description' => '']], $this->overrides->pages());
        $actor = new Actor('7', 'Ada Admin');
        $changed = $this->overrides->savePages(['pages' => ['guides' => ['title' => ' <b>Garden guides</b> ', 'description' => "How to plan,\n plant and prune."], 'home' => ['title' => '', 'description' => '']]], $actor);
        self::assertSame(['Guides'], $changed);
        self::assertSame(['title' => 'Garden guides', 'description' => 'How to plan, plant and prune.'], $this->overrides->page('guides'), 'Plain words on one line.');
        self::assertSame([['page_key' => 'guides', 'updated_by' => '7']], $this->connection->fetchAllAssociative('SELECT page_key, updated_by FROM cattophp_search_pages'), 'A row only for a page with words of its own.');
        $logged = $this->site->activityStore->recent(5, 'search.pages_updated');
        self::assertCount(1, $logged);
        self::assertSame(['pages' => ['Guides']], $logged[0]->details);
        self::assertSame('7', $logged[0]->actorId);
        self::assertSame([], $this->overrides->savePages(['pages' => ['guides' => ['title' => 'Garden guides', 'description' => 'How to plan, plant and prune.']]], $actor), 'The same again changes nothing.');
        self::assertCount(1, $this->site->activityStore->recent(5, 'search.pages_updated'));

        $head = new PageHead($this->pages, $this->site->installation, new EnvironmentValues(['APP_LOCALE' => 'en_ZA']), $this->overrides);
        $meta = $head->complete('guides', ['description' => 'The page\'s own.', 'og' => ['title' => 'Guides', 'description' => 'The page\'s own.']], '/guides', [], 'Guides');
        self::assertSame('How to plan, plant and prune.', $meta['description']);
        self::assertSame('Garden guides', $meta['title']);
        self::assertSame(['title' => 'Garden guides', 'description' => 'How to plan, plant and prune.'], $meta['og']);
        self::assertSame('Garden guides', $head->pagePreview('guides')['title']);
        self::assertSame('Guides', $head->pagePreview('guides')['default_title']);

        $this->overrides->savePages(['pages' => ['guides' => ['title' => '', 'description' => '']]], $actor);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_search_pages'), 'Emptied, the row goes.');
    }

    public function testTooLongIsRefusedAndNothingIsSaved(): void
    {
        foreach ([['title' => str_repeat('a', PageOverrides::TITLE_LIMIT + 1)], ['description' => str_repeat('b', PageOverrides::DESCRIPTION_LIMIT + 1)]] as $typed) {
            try {
                $this->overrides->savePages(['pages' => ['home' => ['title' => 'Fine', 'description' => 'Fine'], 'guides' => $typed]]);
                self::fail('Refused: ' . json_encode($typed));
            } catch (InvalidArgumentException $refused) {
                self::assertStringStartsWith('Guides\'s search ', $refused->getMessage());
                self::assertStringContainsString('Search results show about', $refused->getMessage());
            }
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_search_pages'), 'Not even the page that was fine.');
        self::assertSame(['title' => str_repeat('a', PageOverrides::TITLE_LIMIT), 'description' => ''], PageOverrides::checked(['title' => str_repeat('a', PageOverrides::TITLE_LIMIT), 'description' => ['not text']], 'A thing'));
    }

    public function testRowsAreReadAsAnApplicationStoredThem(): void
    {
        // As cattolms's upgrade migration moves its saved words in: plain columns, any order, a page no longer listed kept but not shown.
        $this->connection->executeStatement("INSERT INTO cattophp_search_pages (page_key, title, description, updated_by) VALUES ('home', 'Gardens', '', NULL), ('retired-page', 'Old', 'Old', '3')");
        self::assertSame(['home' => ['title' => 'Gardens', 'description' => ''], 'guides' => ['title' => '', 'description' => '']], $this->overrides->pages());
    }
}
