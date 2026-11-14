<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel;

use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\Foundations;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A page of a project using the shared parts of a landing page under the real kernel and its strict policy: the rating,
 * the countdown and a list put in order by hand. Its move forms work without JavaScript (each posts its place and the
 * order shown, and the page comes back in the new order), the same forms answered for the script return the list alone,
 * and a move made against an order that has changed is refused with the reason, the list as kept shown.
 */
final class SortableListKernelTest extends \PHPUnit\Framework\TestCase
{
    private string $folder;
    private TestKernel $kernel;
    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->folder = Foundations::folder();
        $this->kernel = new TestKernel($this->folder);
        $this->kernel->boot();
        $browser = $this->kernel->getContainer()->get('test.client');
        self::assertInstanceOf(KernelBrowser::class, $browser);
        $this->browser = $browser;
        $this->browser->disableReboot();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        Foundations::remove($this->folder);
        restore_exception_handler();
    }

    /** @return list<string> */
    private static function order(Crawler $page): array
    {
        return $page->filter('[data-cl-sortable-item]')->each(static fn (Crawler $row): string => (string) $row->attr('data-cl-sortable-item'));
    }

    public function testThePageDrawsTheRatingTheCountdownAndTheListUnderTheStrictPolicy(): void
    {
        $page = $this->browser->request('GET', '/open/arranged');
        self::assertResponseIsOk($this->browser);
        $policy = (string) $this->browser->getResponse()->headers->get('Content-Security-Policy');
        self::assertStringContainsString("style-src 'self'", $policy);
        self::assertStringNotContainsString('style=', (string) $this->browser->getResponse()->getContent(), 'No inline style.');
        self::assertSame(2, $page->filter('script[nonce]')->count(), 'Every script carries the page\'s nonce.');

        self::assertSame('Rated 4.6 out of 5 from 128 reviews', $page->filter('.cl-rating-summary .visually-hidden')->text());
        $timer = $page->filter('[data-cl-countdown-scope] > .cl-ui-countdown[role="timer"]');
        self::assertSame(1, $timer->count());
        self::assertEqualsWithDelta(time(), (int) $timer->attr('data-cl-now'), 5, 'By the server\'s clock.');
        self::assertSame((int) $timer->attr('data-cl-now') + 183840, (int) $timer->attr('data-cl-countdown'));
        self::assertNotNull($timer->filter('[data-cl-countdown-count]')->attr('hidden'), 'Without JavaScript the count waits for the script.');
        self::assertStringStartsWith('Until ', $timer->filter('.cl-ui-countdown-until')->text(), 'The moment shows without JavaScript.');

        self::assertSame(['welcome', 'why', 'answers', 'join'], self::order($page));
        self::assertSame(4, $page->filter('[data-cl-sortable-handle][hidden]')->count(), 'The handles wait for the script.');
        self::assertSame(['Hero', 'Welcome', 'Why join', 'Answers', 'Join now'], $page->filter('.cl-page-sketch > span')->each(static fn (Crawler $cell): string => $cell->text()), 'The drawing has the hero, then a cell for each section.');
        self::assertSame(['full', 'half', 'half', 'full'], $page->filter('.cl-page-sketch > [data-width]')->each(static fn (Crawler $cell): string => (string) $cell->attr('data-width')));
        $first = $page->filter('[data-cl-sortable-item="welcome"] form[data-cl-sortable-move]');
        self::assertSame(['top', 'up', 'down', 'bottom'], $first->each(static fn (Crawler $form): string => (string) $form->attr('data-cl-sortable-move')));
        self::assertSame([true, true, false, false], $first->each(static fn (Crawler $form): bool => $form->filter('button')->attr('disabled') !== null), 'The first cannot go up.');
    }

    public function testAMoveWithoutJavaScriptPostsItsPlaceAndThePageComesBackInTheNewOrder(): void
    {
        $page = $this->browser->request('GET', '/open/arranged');
        $down = $page->filter('[data-cl-sortable-item="welcome"] form[data-cl-sortable-move="down"]');
        self::assertSame(['index' => '1', 'order' => 'welcome,why,answers,join'], array_intersect_key($down->form()->getValues(), ['index' => 1, 'order' => 1]));
        $this->browser->submit($down->form());
        self::assertResponseRedirects($this->browser, '/open/arranged', 303);
        $page = $this->browser->request('GET', '/open/arranged');
        self::assertSame(['why', 'welcome', 'answers', 'join'], self::order($page));

        $this->browser->submit($page->filter('[data-cl-sortable-item="answers"] form[data-cl-sortable-move="top"]')->form());
        self::assertSame(['answers', 'why', 'welcome', 'join'], self::order($this->browser->request('GET', '/open/arranged')), 'Move to top puts it first.');
    }

    public function testTheScriptsMoveGetsTheListAloneAndAStaleOrderIsRefusedWithTheReason(): void
    {
        $page = $this->browser->request('GET', '/open/arranged');
        $bottom = $page->filter('[data-cl-sortable-item="welcome"] form[data-cl-sortable-move="bottom"]')->form();
        // A drag sends the row's first move form with its place replaced: Welcome dropped after Why join.
        $drag = $page->filter('[data-cl-sortable-item="welcome"] form[data-cl-sortable-move]')->first()->form();
        $drag['index'] = '1';
        $answer = $this->browser->submit($drag, [], ['HTTP_HX_REQUEST' => 'true']);
        self::assertResponseIsOk($this->browser);
        self::assertStringStartsWith('<div class="cl-tree cl-tree--landing" id="arranged-list"', ltrim((string) $this->browser->getResponse()->getContent()), 'The list alone, for the script to swap in.');
        self::assertSame(['why', 'welcome', 'answers', 'join'], self::order($answer));
        self::assertSame('', $answer->filter('#arranged-list')->attr('data-error'));

        // The page's other forms still carry the order it showed, which is no longer the order kept.
        $refused = $this->browser->submit($bottom, [], ['HTTP_HX_REQUEST' => 'true']);
        self::assertSame(409, $this->browser->getResponse()->getStatusCode());
        self::assertSame(['why', 'welcome', 'answers', 'join'], self::order($refused), 'The list as it is kept.');
        self::assertStringContainsString('so that move was not made', (string) $refused->filter('#arranged-list')->attr('data-error'));

        // Without JavaScript the refusal comes back on the page, in the error line.
        $this->browser->submit($bottom);
        self::assertResponseRedirects($this->browser, '/open/arranged?refused=1', 303);
        $page = $this->browser->followRedirect();
        $error = $page->filter('[data-cl-sortable-error]');
        self::assertNull($error->attr('hidden'));
        self::assertStringContainsString('so that move was not made', $error->text());
    }

    public function testAMoveWithoutTheFormTokenIsNotMade(): void
    {
        $this->browser->request('POST', '/open/arranged/welcome', ['index' => '3', 'order' => 'welcome,why,answers,join', '_token' => 'not-the-token']);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());
        self::assertSame(['welcome', 'why', 'answers', 'join'], self::order($this->browser->request('GET', '/open/arranged')));
    }

    private static function assertResponseIsOk(KernelBrowser $browser): void
    {
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
    }

    private static function assertResponseRedirects(KernelBrowser $browser, string $to, int $status): void
    {
        self::assertSame($status, $browser->getResponse()->getStatusCode());
        self::assertSame($to, $browser->getResponse()->headers->get('Location'));
    }
}
