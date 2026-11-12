<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Mailing\Consents;
use CattoPHP\Mailing\MailingLists;
use CattoPHP\Mailing\Subscriptions;
use CattoPHP\Mailing\Suppressions;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use InvalidArgumentException;

/** Lists, who agreed to them with every change kept, and the addresses never emailed (cattolms's checks, ported). */
final class MailingListsTest extends DatabaseTestCase
{
    private Foundations $site;
    private MailingLists $lists;
    private Subscriptions $subscriptions;
    private Consents $consents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = new Foundations();
        $this->lists = new MailingLists($this->connection);
        $this->subscriptions = new Subscriptions($this->connection);
        $this->consents = new Consents($this->connection, $this->lists, $this->subscriptions, $this->site->activity);
    }

    public function testAnswersAreKeptWithWhereTheyWereGivenAndEveryChangeIsHistory(): void
    {
        self::assertNull($this->consents->unsubscribe('41', 'course', 7, 'preferences'));
        self::assertNull($this->lists->listId('course', 7, false), 'A no to a list nobody joined makes no list.');

        $news = $this->consents->subscribe('41', 'news', null, 'registration');
        $course = $this->consents->subscribe('41', 'course', 7, 'checkout');
        self::assertIsInt($news);
        self::assertIsInt($course);
        self::assertNull($this->consents->subscribe('41', 'course', '7', 'follow_button'), 'The same yes again changes nothing, the subject as a number or as text.');
        self::assertSame($course, $this->lists->listId('course', '7', false), 'One list for each kind and subject.');
        self::assertTrue($this->subscriptions->isSubscribed('41', 'news', null));
        self::assertTrue($this->subscriptions->isSubscribed('41', 'course', 7));
        self::assertFalse($this->subscriptions->isSubscribed('42', 'course', 7));
        self::assertSame('checkout', $this->connection->fetchOne('SELECT source FROM cattophp_mailing_subscriptions WHERE list_id = :l AND account = :a', ['l' => $course, 'a' => '41']));

        self::assertSame($course, $this->consents->unsubscribe('41', 'course', 7, 'preferences'));
        self::assertFalse($this->subscriptions->isSubscribed('41', 'course', 7));
        self::assertSame([['unsubscribed', 'preferences'], ['subscribed', 'checkout']], array_map(static fn(array $r): array => [$r['status'], $r['source']], $this->subscriptions->history('41', $course)), 'Every change is history, newest first.');
        self::assertCount(3, $this->subscriptions->history('41'));
        self::assertSame([$news], $this->subscriptions->subscribedListIds('41'));
        $this->consents->subscribe('43', 'course', 7, 'follow_button');
        $this->consents->subscribe('41', 'course', 9, 'follow_button');
        self::assertSame(1, $this->subscriptions->followerCount($course));
        self::assertSame(['9'], $this->subscriptions->followedAmong('41', 'course', [7, 9, 11]));
        self::assertSame([], $this->subscriptions->followedAmong('41', 'course', []));

        $actions = array_map(static fn($entry): array => [$entry->action, $entry->details, $entry->actorId], array_reverse($this->site->activity->recent()));
        self::assertSame(['mailing.subscribed', ['kind' => 'news', 'subject_id' => null, 'source' => 'registration'], '41'], $actions[0], 'The person is the one who acted.');
        self::assertSame(['mailing.subscribed', ['kind' => 'course', 'subject_id' => 7, 'source' => 'checkout'], '41'], $actions[1]);
        self::assertSame('mailing.unsubscribed', $actions[2][0]);
    }

    public function testAnAdministratorsListIsJoinedWhileItIsOpenAndStoppedAloneOrWithTheRest(): void
    {
        $list = $this->lists->createOwn('Early access', 'New courses before anyone else.', true, '1');
        $own = $this->lists->ownList($list);
        self::assertSame(['own', 'Early access', 'New courses before anyone else.', true, 0], [$own['kind'] ?? null, $own['name'] ?? null, $own['description'] ?? null, $own['shown'] ?? null, $own['followers'] ?? null]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) ($own['join_token'] ?? ''));
        self::assertSame($list, $this->lists->ownListByToken((string) ($own['join_token'] ?? ''))['id'] ?? null);
        self::assertNull($this->lists->ownListByToken('not-a-token'));
        self::assertNull($this->lists->listId('own', null, true), 'A list of one\'s own is never made by a follow.');
        $this->expectExceptionOnJoining('1', 999999);

        self::assertTrue($this->consents->joinList('51', $list, 'join_link'));
        self::assertFalse($this->consents->joinList('51', $list, 'join_link'), 'On it already.');
        $news = (int) $this->consents->subscribe('51', 'news', null, 'registration');
        self::assertTrue($this->consents->isSubscribedToList('51', $list));

        $token = $this->lists->newJoinToken($list);
        self::assertNull($this->lists->ownListByToken((string) ($own['join_token'] ?? '')), 'The old join link stops working.');
        self::assertSame($list, $this->lists->ownListByToken($token)['id'] ?? null);
        $this->lists->update($list, ['closed_at' => new \DateTimeImmutable('2026-11-12 10:00:00+00:00'), 'from_name' => 'Early Access', 'reply_to' => 'early@example.test', 'not_a_column' => 'x']);
        self::assertSame(['Early Access', 'early@example.test'], [$this->lists->find($list)['from_name'] ?? null, $this->lists->find($list)['reply_to'] ?? null]);
        self::assertNull($this->lists->ownListByToken($token), 'A closed list cannot be joined by its link.');
        self::assertSame([], $this->lists->ownLists(true));
        self::assertCount(1, $this->lists->ownLists());
        $this->expectExceptionOnJoining('52', $list);

        self::assertTrue($this->consents->unsubscribeList('51', $list, 'unsubscribe_link'));
        self::assertFalse($this->consents->unsubscribeList('51', $list, 'unsubscribe_link'));
        $this->consents->joinList('51', $this->lists->createOwn('Second', '', false), 'join_link');
        self::assertSame(2, $this->consents->unsubscribeAll('51', 'complaint'));
        self::assertSame(0, $this->consents->unsubscribeAll('51', 'complaint'));
        self::assertFalse($this->consents->isSubscribedToList('51', $news));
        $last = $this->site->activity->recent()[0];
        self::assertSame(['mailing.unsubscribed_all', ['lists' => 2, 'source' => 'complaint']], [$last->action, $last->details]);

        $this->subscriptions->forget('51');
        $this->lists->forget('1');
        self::assertSame([], $this->subscriptions->history('51'), 'A person whose account is gone is forgotten.');
        self::assertSame('0', (string) $this->connection->fetchOne("SELECT COUNT(*) FROM cattophp_mailing_lists WHERE created_by = '1'"));
    }

    public function testAnAddressNeverEmailedKeepsWhyAndCanBeTakenOff(): void
    {
        $never = new Suppressions($this->connection);
        self::assertTrue($never->suppress(' Ayanda@Example.TEST ', 'hard_bounce', '550 No such user', 31));
        self::assertFalse($never->suppress('ayanda@example.test', 'complaint'), 'Never emailed already.');
        self::assertTrue($never->isSuppressed('AYANDA@example.test'));
        self::assertTrue($never->suppress('chris@example.test', 'manual', 'Asked never to be emailed.', null, '7'));
        self::assertSame([2, 1], [$never->count(), $never->count('chris')]);
        self::assertSame(['hard_bounce', '550 No such user', '31'], array_values($this->connection->fetchAssociative("SELECT reason, detail, copy_id FROM cattophp_mailing_suppressions WHERE email = 'ayanda@example.test'") ?: []));
        $never->forgetCopy(31);
        $never->forget('7');
        self::assertSame([null, null], [$this->connection->fetchOne("SELECT copy_id FROM cattophp_mailing_suppressions WHERE email = 'ayanda@example.test'"), $this->connection->fetchOne("SELECT created_by FROM cattophp_mailing_suppressions WHERE email = 'chris@example.test'")]);
        self::assertTrue($never->lift('Chris@example.test'));
        self::assertFalse($never->lift('chris@example.test'));
        self::assertFalse($never->isSuppressed('chris@example.test'));
        $this->expectException(InvalidArgumentException::class);
        $never->suppress('someone@example.test', 'because');
    }

    private function expectExceptionOnJoining(string $account, int $list): void
    {
        try {
            $this->consents->joinList($account, $list, 'join_link');
            self::fail('Joined a list that cannot be joined.');
        } catch (InvalidArgumentException $refused) {
            self::assertSame('That list cannot be joined now.', $refused->getMessage());
        }
    }
}
