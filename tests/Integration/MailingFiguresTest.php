<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Mailing\Campaigns;
use CattoPHP\Mailing\Consents;
use CattoPHP\Mailing\Copies;
use CattoPHP\Mailing\Links;
use CattoPHP\Mailing\MailingFigures;
use CattoPHP\Mailing\MailingLists;
use CattoPHP\Mailing\Subscriptions;
use CattoPHP\Tests\Support\DatabaseTestCase;

/** A mailing's figures for its overview and reports (cattolms's report and overview reads, ported). */
final class MailingFiguresTest extends DatabaseTestCase
{
    public function testTheFiguresOfAPeriodAndOfTheOverview(): void
    {
        $lists = new MailingLists($this->connection);
        $subscriptions = new Subscriptions($this->connection);
        $consents = new Consents($this->connection, $lists, $subscriptions);
        $campaigns = new Campaigns($this->connection);
        $copies = new Copies($this->connection);
        $figures = new MailingFigures($this->connection);
        $news = (int) $consents->subscribe('1', 'news', null, 'registration');
        $consents->subscribe('2', 'news', null, 'registration');
        $consents->subscribe('2', 'course', 7, 'follow_button');
        $consents->unsubscribe('3', 'course', 7, 'preferences');
        $consents->subscribe('3', 'course', 7, 'follow_button');
        $consents->unsubscribe('3', 'course', 7, 'preferences');
        $message = $campaigns->create('message', 'Monthly news', '', ['list_ids' => [$news], 'status' => 'sent']);
        $digest = $campaigns->create('digest', 'Digest', '', ['status' => 'sent']);
        $later = $campaigns->create('message', 'Later', '', ['list_ids' => [$news], 'status' => 'scheduled']);
        $campaigns->update($later, ['send_at' => '2026-12-01 07:00:00+00']);
        foreach ([$message, $digest] as $campaign) $campaigns->update($campaign, ['started_at' => '2026-11-09 08:00:00+00']);
        $copies->add($message, [['account' => '1', 'email' => 'one@example.test', 'list_id' => $news], ['account' => '2', 'email' => 'two@example.test', 'list_id' => $news]]);
        $copies->add($digest, [['account' => '2', 'email' => 'two@example.test']]);
        $ids = array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM cattophp_mailing_copies ORDER BY id'));
        foreach ($ids as $id) $copies->update($id, ['status' => 'delivered', 'handed_at' => '2026-11-09 08:30:00+00']);
        $copies->update($ids[1], ['status' => 'bounced', 'unsubscribed_at' => '2026-11-09 09:00:00+00']);
        $copies->recordOpen($ids[0]);
        $links = new Links($this->connection);
        $links->recordClick($ids[0], $links->id($message, 'https://example.test/courses'));
        $this->connection->executeStatement("UPDATE cattophp_mailing_link_clicks SET first_at = '2026-11-09 09:00:00+00'");
        $this->connection->executeStatement("INSERT INTO cattophp_mailing_forwards (copy_id, account, to_email, token, created_at) VALUES (:copy, '1', 'friend@example.test', :token, '2026-11-09 10:00:00+00')", ['copy' => $ids[0], 'token' => str_repeat('a', 32)]);
        $this->connection->executeStatement("UPDATE cattophp_mailing_subscription_events SET created_at = '2026-11-09 07:00:00+00'");
        [$from, $to] = ['2026-11-09 00:00:00+00', '2026-11-10 00:00:00+00'];

        self::assertSame(['sent' => 3, 'opened' => 1, 'clicked' => 1, 'bounced' => 1, 'complained' => 0, 'unsubscribed' => 1], $figures->totals($from, $to));
        self::assertSame(0, $figures->totals('2026-11-10 00:00:00+00', '2026-11-11 00:00:00+00')['sent'], 'Nothing in another period.');
        self::assertSame(1, $figures->forwarded($from, $to));
        self::assertSame([3, 1], [$figures->people('subscribed', $from, $to), $figures->people('unsubscribed', $from, $to)]);
        self::assertSame([['kind' => 'message', 'sent' => 2, 'opened' => 1, 'clicked' => 1], ['kind' => 'digest', 'sent' => 1, 'opened' => 0, 'clicked' => 0]], $figures->byKind($from, $to));
        self::assertSame(['Monthly news'], array_column($figures->campaigns($from, $to, ['message']), 'name'));
        self::assertCount(2, $figures->campaigns($from, $to, ['message', 'digest']));
        self::assertSame([['url' => 'https://example.test/courses', 'campaign_id' => $message, 'campaign' => 'Monthly news', 'people' => 1, 'clicks' => 1]], $figures->links($from, $to));
        self::assertSame(strtotime('2026-11-09 08:30:00+00'), strtotime((string) $figures->firstHanded()), 'When the first copy was handed on.');
        self::assertSame([['slice' => '2026-11-09 00:00:00', 'sent' => 3]], $figures->slices('day', 'UTC', $from, $to));

        self::assertSame(['total' => 2, 'joined' => 2], $figures->subscribers('2026-11-01 00:00:00+00'), 'Person 3 is on no list.');
        self::assertSame(['course' => 1, 'news' => 1], self::sorted($figures->listsByKind()));
        self::assertSame(['Digest', 'Monthly news'], array_column($figures->recentlySent(['message', 'digest'], 5), 'name'), 'Newest first, then by id.');
        self::assertSame(['sent' => 3, 'bounced' => 1, 'complained' => 0], $figures->bouncesSince($from));
        self::assertSame([['list_id' => $news, 'joined' => 2]], array_slice($figures->growing($from, 5), 0, 1));
        self::assertSame([['id' => $later, 'name' => 'Later', 'send_at' => (string) $this->connection->fetchOne('SELECT send_at FROM cattophp_mailing_campaigns WHERE id = :id', ['id' => $later]), 'list_ids' => [$news]]], $figures->scheduled('message'));
    }

    /**
     * @param array<string,int> $counts
     * @return array<string,int>
     */
    private static function sorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }
}
