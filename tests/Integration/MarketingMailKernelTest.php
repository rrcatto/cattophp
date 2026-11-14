<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Activity\ActivityEntry;
use CattoPHP\Activity\Actor;
use CattoPHP\Mailing\Campaigns as MailingMessages;
use CattoPHP\Mailing\Copies;
use CattoPHP\Mailing\MailingLists;
use CattoPHP\Marketing\CampaignInput;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Channels;
use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\Mail\MailedLinks;
use CattoPHP\Marketing\Mail\MailingPublications;
use CattoPHP\Marketing\MarketingRefused;
use CattoPHP\Marketing\Publications;
use CattoPHP\Marketing\Touch;
use CattoPHP\Marketing\Touches;
use CattoPHP\Marketing\Visitors;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * A mailing message counted for a campaign, as a project with mailing and the Campaign Marketing Manager gets it: tied,
 * tied again, tied to another campaign, untied while nobody has clicked (its publication deleted) and after (retired,
 * then put back); a click on a tied message's link to this site recording one touch with the link's own address and
 * tags and tying the account; a link elsewhere, a message tied to nothing, a copy without an account and a browser asking
 * not to be tracked; and an opening recorded once for each copy.
 */
final class MarketingMailKernelTest extends DatabaseTestCase
{
    private const COMPUTER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:146.0) Gecko/20100101 Firefox/146.0';

    private string $folder;
    private TestKernel $kernel;
    private int $list;
    private int $message;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = Foundations::folder();
        $this->kernel = new TestKernel($this->folder, (string) getenv('CATTOPHP_TEST_DATABASE_URL'), mailing: true, marketing: true);
        $this->kernel->boot();
        $campaigns = $this->service(Campaigns::class);
        foreach (['spring-sale' => 'Spring sale', 'winter-warmers' => 'Winter warmers', 'old-autumn' => 'Old autumn'] as $code => $name) {
            $campaigns->create(new CampaignInput($name, $code, destination: '/shop'));
            $campaigns->activate($code);
        }
        $campaigns->archive('old-autumn');
        $this->list = $this->service(MailingLists::class)->createOwn('News and updates', '', true);
        $this->message = $this->service(MailingMessages::class)->create('message', 'November news', '9', ['subject' => 'News', 'list_ids' => [$this->list]]);
        $this->service(Copies::class)->add($this->message, [['account' => '71', 'email' => 'thandi@example.com', 'list_id' => $this->list],
            ['account' => '72', 'email' => 'sipho@example.com', 'list_id' => $this->list]]);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        Foundations::remove($this->folder);
        restore_exception_handler();
        parent::tearDown();
    }

    public function testAMessageIsTiedTiedAgainMovedAndUntied(): void
    {
        $tied = $this->service(MailingPublications::class);
        $actor = new Actor('9', 'Lerato Dlamini');
        $publication = $tied->tie($this->message, 'spring-sale', '', $actor);
        self::assertSame(['email', 'em1', 'News and updates', MailingPublications::SOURCE, (string) $this->message, null],
            [$publication->channelKey, $publication->code, $publication->place, $publication->sourceType, $publication->sourceReference, $publication->publishedOn]);
        self::assertSame($publication->id, $tied->forMessage($this->message)?->id);
        self::assertSame(['marketing.publication.tied', 'marketing.publication_created'], array_values(array_intersect(['marketing.publication.tied', 'marketing.publication_created'],
            $this->history('spring-sale'))), 'In the campaign\'s history.');

        $again = $tied->tie($this->message, 'spring-sale', 'Members\' news', $actor);
        self::assertSame([$publication->id, 'em1', 'Members\' news'], [$again->id, $again->code, $again->place], 'Tied again: the same publication, its place changed.');
        $this->service(MailingMessages::class)->update($this->message, ['started_at' => '2026-11-20 08:30:00+00']);
        self::assertSame('2026-11-20', $tied->refresh($again)->publishedOn?->format('Y-m-d'), 'Published the day it started sending.');

        $moved = $tied->tie($this->message, 'winter-warmers', '', $actor);
        self::assertSame(['winter-warmers', 'em1', '2026-11-20'], [$this->service(Campaigns::class)->byId($moved->campaignId)?->code, $moved->code, $moved->publishedOn?->format('Y-m-d')]);
        self::assertSame([], $this->service(Publications::class)->list((int) $this->service(Campaigns::class)->get('spring-sale')?->id), 'Nobody had clicked: the first is deleted.');
        self::assertContains('marketing.publication.untied', $this->history('spring-sale'));

        $this->click('https://example.com/shop/tea-100');
        $tied->untie($this->message, $actor);
        self::assertNull($tied->forMessage($this->message));
        $kept = $this->service(Publications::class)->list((int) $this->service(Campaigns::class)->get('winter-warmers')?->id);
        self::assertSame([[$moved->id, true]], array_map(static fn($p): array => [$p->id, $p->isRetired()], $kept), 'Clicked: retired, with its clicks.');
        $back = $tied->tie($this->message, 'winter-warmers', '', $actor);
        self::assertSame([$moved->id, false], [$back->id, $back->isRetired()], 'Tied again: the retired one is put back.');
        $tied->untie(999999);

        foreach ([[$this->message, 'old-autumn'], [999999, 'spring-sale'], [$this->message, 'no-such-campaign']] as [$message, $campaign]) {
            try {
                $tied->tie($message, $campaign);
                self::fail('Tied to ' . $campaign);
            } catch (MarketingRefused $refused) {
                self::assertNotSame('', $refused->getMessage());
            }
        }

        $this->service(Channels::class)->retire('email');
        $second = $this->service(MailingMessages::class)->create('message', 'December news', '9', ['list_ids' => []]);
        $other = $tied->tie($second, 'spring-sale');
        self::assertSame(['other', 'em1', 'December news'], [$other->channelKey, $other->code, $other->place], 'With email retired, on Other; with no list, named by the message.');
    }

    public function testAClickOnATiedMessagesLinkToThisSiteRecordsOneTouchAndTiesTheAccount(): void
    {
        $links = $this->service(MailedLinks::class);
        self::assertNull($links->followed($this->token('71'), 'https://example.com/shop/tea-100', $this->request()), 'A message counted for no campaign records nothing.');
        self::assertSame([], $this->touches());

        $publication = $this->service(MailingPublications::class)->tie($this->message, 'spring-sale');
        $tracked = $links->followed($this->token('71'), 'https://example.com/shop/tea-100?utm_source=news&utm_content=november#buy', $this->request());
        self::assertNotNull($tracked);
        self::assertSame(Visitors::COOKIE, $tracked->cookie?->getName(), 'The caller sets the cookie on its redirect.');
        $touch = $this->touches()[0];
        self::assertSame([$publication->id, 'email', 'news', 'november', 'https://example.com/shop/tea-100?utm_source=news&utm_content=november#buy', true],
            [$touch->publicationId, $touch->source, $touch->utm['utm_source'], $touch->utm['utm_content'], $touch->landing, $touch->counted]);
        self::assertSame('71', $this->service(Visitors::class)->byId((int) $tracked->visitorId)?->account, 'The person it was sent to is the visitor now.');

        foreach (['https://elsewhere.example.org/shop', '//example.com.evil.example.org/x', 'javascript:alert(1)', 'mailto:help@example.com', 'https://user@example.com/x', ''] as $elsewhere) {
            self::assertNull($links->followed($this->token('71'), $elsewhere, $this->request()), $elsewhere);
        }
        self::assertCount(1, $this->touches(), 'Links to other sites record nothing.');
        self::assertNotNull($links->followed($this->token('72'), '/shop?colour=red', $this->request()), 'A path on this site.');

        $private = $links->followed($this->token('72'), '/shop', $this->request(['HTTP_DNT' => '1']));
        self::assertSame([null, null, true], [$private?->visitorId, $private?->cookie, $private?->doNotTrack], 'Counted, with no visitor and no cookie.');
        $scanner = $links->followed($this->token('72'), '/shop', $this->request(['HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Proofpoint URL Defense)']));
        self::assertSame(['bot', null], [$scanner?->agent, $scanner?->visitorId]);
        self::assertSame([false, 'bot'], [$this->touches()[0]->counted, $this->touches()[0]->notCountedBecause], 'An email link scanner\'s opening is set aside.');

        $this->service(Copies::class)->forget('72');
        self::assertNull($links->followed($this->token(null), '/shop', $this->request()), 'A copy whose person is gone records nothing.');
        self::assertNull($links->followed('0123456789abcdef0123456789abcdef', '/shop', $this->request()), 'Nor an unknown copy.');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_marketing_events'), 'A click is a touch, not an event.');
    }

    public function testAnOpeningIsRecordedOnceForEachCopyWithItsAccountAndNoVisitor(): void
    {
        $links = $this->service(MailedLinks::class);
        $links->opened($this->token('71'));
        self::assertSame([], $this->service(Events::class)->recent(), 'A message counted for no campaign.');
        $publication = $this->service(MailingPublications::class)->tie($this->message, 'spring-sale');
        $links->opened($this->token('71'));
        $links->opened($this->token('71'));
        $links->opened($this->token('72'));
        $links->opened('not-a-token');
        $events = array_reverse($this->service(Events::class)->recent());
        self::assertSame([[Events::EMAIL_OPENED, '71', null, $publication->campaignId, $publication->id], [Events::EMAIL_OPENED, '72', null, $publication->campaignId, $publication->id]],
            array_map(static fn($e): array => [$e->kind, $e->account, $e->visitorId, $e->campaignId, $e->publicationId], $events), 'Once for each copy.');
        self::assertStringStartsWith('email_opened:', (string) $this->service(Events::class)->latest([Events::EMAIL_OPENED], null, '71')?->idempotencyKey);
    }

    public function testWithoutMailingTheMailPartIsNotThere(): void
    {
        $folder = Foundations::folder();
        $kernel = new TestKernel($folder, (string) getenv('CATTOPHP_TEST_DATABASE_URL'), marketing: true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            self::assertFalse($container->has(MailedLinks::class));
            self::assertFalse($container->has(MailingPublications::class));
        } finally {
            $kernel->shutdown();
            Foundations::remove($folder);
            restore_exception_handler();
        }
    }

    /** A person clicking a tied message's link. */
    private function click(string $url): void
    {
        self::assertNotNull($this->service(MailedLinks::class)->followed($this->token('71'), $url, $this->request()));
    }

    /** The token of a person's copy of the message (null: the copy whose person is gone). */
    private function token(?string $account): string
    {
        return (string) $this->connection->fetchOne('SELECT token FROM cattophp_mailing_copies WHERE campaign_id = :message AND ' . ($account === null ? 'account IS NULL' : 'account = :account'),
            ['message' => $this->message] + ($account === null ? [] : ['account' => $account]));
    }

    /** @param array<string,string> $server */
    private function request(array $server = []): Request
    {
        return Request::create('https://example.com/m/c/x', 'GET', [], [], [], $server + ['HTTP_USER_AGENT' => self::COMPUTER, 'REMOTE_ADDR' => '198.51.100.7']);
    }

    /** @return list<Touch> every touch, the newest first */
    private function touches(): array
    {
        $ids = array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM ' . Touches::TABLE . ' ORDER BY id DESC'));

        return array_values(array_filter(array_map(fn(int $id): ?Touch => $this->service(Touches::class)->byId($id), $ids)));
    }

    /** @return list<string> a campaign's history, the newest first */
    private function history(string $code): array
    {
        $campaign = $this->service(Campaigns::class)->get($code) ?? throw new \LogicException('No such campaign.');

        return array_map(static fn(ActivityEntry $entry): string => $entry->action, $this->service(Campaigns::class)->history($campaign));
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function service(string $class): object
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $service = $container->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
