<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Mailing\CampaignProject;
use CattoPHP\Mailing\Campaigns;
use CattoPHP\Mailing\CampaignSender;
use CattoPHP\Mailing\CattoMail\CattoMailClient;
use CattoPHP\Mailing\CattoMail\CattoMailEvents;
use CattoPHP\Mailing\CattoMail\CattoMailTransport;
use CattoPHP\Mailing\CattoMail\CopyEvents;
use CattoPHP\Mailing\Consents;
use CattoPHP\Mailing\Copies;
use CattoPHP\Mailing\CopyOutcomes;
use CattoPHP\Mailing\DrawnCopy;
use CattoPHP\Mailing\Links;
use CattoPHP\Mailing\MailingLists;
use CattoPHP\Mailing\MailingSettingGroup;
use CattoPHP\Mailing\MailingSettings;
use CattoPHP\Mailing\MailingTransport;
use CattoPHP\Mailing\MailingTransports;
use CattoPHP\Mailing\SenderIdentity;
use CattoPHP\Mailing\SendingRules;
use CattoPHP\Mailing\SendResult;
use CattoPHP\Mailing\Subscriptions;
use CattoPHP\Mailing\Suppressions;
use CattoPHP\Mailing\TransportRefused;
use CattoPHP\Mailing\TransportUnavailable;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Campaigns sent a little each run, to the people on their lists, within the rules (cattolms's sender checks, ported). */
final class CampaignsTest extends DatabaseTestCase
{
    private Foundations $site;
    private Campaigns $campaigns;
    private Copies $copies;
    private MailingLists $lists;
    private Subscriptions $subscriptions;
    private Consents $consents;
    private Suppressions $never;
    private CopyOutcomes $outcomes;
    /** @var list<array{mailing:string,kind:string,copies:list<\CattoPHP\Mailing\OutgoingCopy>}> */
    public array $sent = [];
    public ?\Throwable $refuse = null;
    public SendingRules $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = new Foundations(settingsProviders: [new MailingSettingGroup()]);
        $this->campaigns = new Campaigns($this->connection);
        $this->copies = new Copies($this->connection);
        $this->lists = new MailingLists($this->connection);
        $this->subscriptions = new Subscriptions($this->connection);
        $this->consents = new Consents($this->connection, $this->lists, $this->subscriptions, $this->site->activity);
        $this->never = new Suppressions($this->connection);
        $this->outcomes = new CopyOutcomes($this->copies, $this->never, $this->consents, $this->site->activity);
        $this->rules = new SendingRules(firstDayLimit: 0, sendFrom: '00:00', sendUntil: '00:00');
    }

    public function testACampaignGoesToItsListsWithinItsLimitAndSkipsWhoShouldNotGetIt(): void
    {
        $news = (int) $this->consents->subscribe('1', 'news', null, 'registration');
        foreach (['2', '3', '4', '5'] as $account) $this->consents->subscribe($account, 'news', null, 'registration');
        $this->never->suppress('person3@example.test', 'manual');
        $id = $this->campaigns->create('message', 'Monthly news', '9', ['subject' => 'News', 'content' => [['type' => 'text', 'text' => 'Hello']], 'list_ids' => [$news], 'status' => 'scheduled']);
        $this->campaigns->update($id, ['max_recipients' => 4]);
        $sender = $this->sender();

        self::assertSame(['started' => 1, 'handed' => 3, 'paused' => 0, 'resolved' => 0, 'notes' => []], $sender->run(), 'Four people taken (the most it sends to), one of them never emailed.');
        self::assertSame(['handed', 'handed', 'suppressed', 'handed'], array_map(fn(int $copy): string => (string) ($this->copies->byId($copy)['status'] ?? ''), $this->copyIds($id)));
        self::assertSame(['sent', '9'], [$this->campaigns->find($id)['status'] ?? null, $this->campaigns->find($id)['created_by'] ?? null]);
        self::assertSame([(string) $id, 'message'], [$this->sent[0]['mailing'], $this->sent[0]['kind']]);
        self::assertSame(['person1@example.test', 'person2@example.test', 'person4@example.test'], array_map(static fn($copy): string => $copy->to, $this->sent[0]['copies']));
        self::assertSame(['News for person1', (string) $news], [$this->sent[0]['copies'][0]->subject, $this->sent[0]['copies'][0]->listId], 'Drawn by the project.');
        $figures = $this->campaigns->figures($id);
        self::assertSame([4, 0, 3, 1], [$figures['total'], $figures['queued'], $figures['sent'], $figures['not_sent']]);
        self::assertSame([1, 'Monthly news'], [$this->campaigns->count('monthly'), $this->campaigns->page('news', 10, 0)[0]['name'] ?? null]);
        self::assertEquals([['type' => 'text', 'text' => 'Hello']], $this->campaigns->find($id)['content'] ?? null, 'What it says, as the project gave it (a JSON object keeps no order).');

        // Copies added as they come (a digest): one whose person stopped the list, one stopped by every list that brought
        // it, one whose account is gone, one that goes.
        $other = $this->lists->createOwn('Other', '', true);
        $digest = $this->campaigns->create('digest', 'Digest', '', ['status' => 'sending']);
        $this->copies->add($digest, [['account' => '8', 'email' => 'person8@example.test', 'list_id' => $news, 'reasons' => ['News and Updates']],
            ['account' => '9', 'email' => 'person9@example.test', 'reasons' => ['News and Updates', 'Other'], 'content' => ['lists' => [$news, $other]]],
            ['account' => '10', 'email' => 'person10@example.test', 'list_id' => $news],
            ['account' => '1', 'email' => 'person1@example.test', 'list_id' => $news]]);
        $this->copies->forget('10');
        self::assertSame(1, $sender->run()['handed']);
        self::assertSame(['They unsubscribed from it before it was sent.', 'They unsubscribed from everything that brought it before it was sent.', 'The account was deleted before it was sent.', null],
            array_map(fn(int $copy): ?string => $this->copies->byId($copy)['status_detail'] ?? null, $this->copyIds($digest)));
        self::assertSame('sent', $this->campaigns->find($digest)['status'] ?? null);

        $empty = $this->campaigns->create('message', 'Nobody', '', ['list_ids' => [$other], 'status' => 'scheduled']);
        $sender->run();
        self::assertSame(['sent', 'Nobody followed its lists when it was due, so nothing was sent.'], [$this->campaigns->find($empty)['status'] ?? null, $this->campaigns->find($empty)['status_note'] ?? null]);
        $this->campaigns->delete($id);
        self::assertNotNull($this->campaigns->find($id), 'A campaign that sent copies is never deleted.');
        $draft = $this->campaigns->create('message', 'Draft', '');
        $this->campaigns->delete($draft);
        self::assertNull($this->campaigns->find($draft));
    }

    public function testNothingGoesOutsideTheHoursAndTheFirstDaysStartGently(): void
    {
        $news = (int) $this->consents->subscribe('1', 'news', null, 'registration');
        for ($i = 2; $i <= 30; $i++) $this->consents->subscribe((string) $i, 'news', null, 'registration');
        $id = $this->campaigns->create('message', 'Big', '', ['list_ids' => [$news], 'status' => 'scheduled']);
        $this->site->clock->modify('2026-11-09 22:00:00 UTC');
        $this->rules = new SendingRules(hourlyLimit: 300, firstDayLimit: 5, sendFrom: '07:00', sendUntil: '20:00');
        $sender = $this->sender();
        self::assertSame(0, $sender->allowance(), 'Outside the sending hours.');
        self::assertSame(0, $sender->run()['handed']);
        self::assertTrue($this->rules->withinSendingHours(new \DateTimeImmutable('2026-11-09 08:30:00', new \DateTimeZone('UTC')), new \DateTimeZone('UTC')));
        self::assertTrue((new SendingRules(sendFrom: '20:00', sendUntil: '06:00'))->withinSendingHours(new \DateTimeImmutable('2026-11-09 02:00:00', new \DateTimeZone('UTC')), new \DateTimeZone('UTC')), 'Through midnight.');

        $this->rules = new SendingRules(hourlyLimit: 300, firstDayLimit: 5, sendFrom: '00:00', sendUntil: '00:00');
        self::assertSame(5, $sender->run()['handed'], 'The first day: five.');
        self::assertSame(0, $sender->run()['handed']);
        $this->site->clock->modify('+1 day');
        self::assertSame(10, $sender->allowance(), 'Doubled the next day.');
        $this->rules = new SendingRules(hourlyLimit: 7, firstDayLimit: 0, sendFrom: '00:00', sendUntil: '00:00');
        self::assertSame(7, $sender->run()['handed'], 'The hourly limit.');
        self::assertSame(0, $sender->allowance());
        self::assertSame('sending', $this->campaigns->find($id)['status'] ?? null);
    }

    public function testACampaignPausesItselfOnBouncesAndARefusalAndWaitsWhenTheTransportIsBusy(): void
    {
        $news = (int) $this->consents->subscribe('1', 'news', null, 'registration');
        for ($i = 2; $i <= 6; $i++) $this->consents->subscribe((string) $i, 'news', null, 'registration');
        $id = $this->campaigns->create('message', 'Bouncy', '', ['list_ids' => [$news], 'status' => 'scheduled']);
        $this->rules = new SendingRules(firstDayLimit: 0, pauseBouncePercent: 10.0, judgeAfter: 2, sendFrom: '00:00', sendUntil: '00:00');
        $this->refuse = new TransportUnavailable('Busy.');
        $sender = $this->sender();
        $done = $sender->run();
        self::assertSame([0, ['Message ' . $id . ' waits: Busy.']], [$done['handed'], $done['notes']]);
        self::assertSame(6, $this->copies->queuedCount($id), 'Busy: everything stays queued.');

        $this->refuse = null;
        $this->rules = new SendingRules(hourlyLimit: 3, firstDayLimit: 0, pauseBouncePercent: 10.0, judgeAfter: 2, sendFrom: '00:00', sendUntil: '00:00');
        self::assertSame(3, $sender->run()['handed']);
        $copy = $this->copies->byId($this->copyIds($id)[0]) ?? [];
        self::assertTrue($this->outcomes->record($copy, 'bounced', 'No such mailbox'));
        self::assertFalse($this->outcomes->record($this->copies->byId((int) $copy['id']) ?? [], 'delivered'), 'A copy never moves back.');
        self::assertTrue($this->never->isSuppressed((string) $copy['email']), 'A hard bounce is never emailed again.');
        self::assertTrue($this->subscriptions->isSubscribed('1', 'news', null), 'A bounce is not a choice: what they follow stays.');
        $done = $sender->run();
        self::assertSame(1, $done['paused']);
        self::assertStringStartsWith('Paused by itself: 1 of the 3 emails sent bounced (33.33%), above the limit of 10%.', (string) ($this->campaigns->find($id)['status_note'] ?? ''));
        $this->campaigns->update($id, ['status' => 'sending', 'checks_overridden' => true]);
        $this->rules = new SendingRules(hourlyLimit: 10, firstDayLimit: 0, pauseBouncePercent: 10.0, judgeAfter: 2, sendFrom: '00:00', sendUntil: '00:00');
        $this->refuse = new TransportRefused('The sending domain is not verified.');
        $sender->run();
        self::assertSame(['paused', 'Paused: The sending domain is not verified. Nothing more was sent. Fix it, then resume.'], [$this->campaigns->find($id)['status'] ?? null, $this->campaigns->find($id)['status_note'] ?? null]);
        self::assertTrue($this->campaigns->find($id)['checks_overridden'] ?? null);
        self::assertSame([$id], array_map(static fn(array $c): int => (int) $c['id'], $this->campaigns->waiting()));
    }

    public function testAComplaintAndAnUnsubscribeLinkStopWhatTheyShould(): void
    {
        $news = (int) $this->consents->subscribe('1', 'news', null, 'registration');
        $course = (int) $this->consents->subscribe('1', 'course', 7, 'follow_button');
        $this->consents->subscribe('2', 'news', null, 'registration');
        $this->consents->subscribe('2', 'course', 7, 'follow_button');
        $id = $this->campaigns->create('digest', 'Digest', '');
        $this->copies->add($id, [['account' => '1', 'email' => 'person1@example.test', 'list_id' => null, 'reasons' => ['News and Updates', 'Course 7'], 'content' => ['lists' => [$news, $course]]],
            ['account' => '2', 'email' => 'person2@example.test', 'list_id' => $course, 'reasons' => ['Course 7']]]);
        $copies = $this->copyIds($id);
        self::assertSame(2, $this->outcomes->unsubscribe($this->copies->byId($copies[0]) ?? [], false, 'unsubscribe_link'), 'A digest brought by two lists stops both.');
        self::assertSame([], $this->subscriptions->subscribedListIds('1'));
        self::assertNotNull($this->copies->byId($copies[0])['unsubscribed_at'] ?? null);
        self::assertTrue($this->outcomes->record($this->copies->byId($copies[1]) ?? [], 'complained', '', 'msg-9'));
        self::assertSame([], $this->subscriptions->subscribedListIds('2'), 'A complaint stops everything.');
        self::assertTrue($this->never->isSuppressed('person2@example.test'));
        self::assertSame('msg-9', $this->copies->byId($copies[1])['transport_message_id'] ?? null, 'Its message id kept.');
        $entries = array_map(static fn($entry): string => $entry->action, $this->site->activity->recent());
        self::assertContains('mailing.complaint', $entries);
        self::assertSame([1 => $copies[0], 2 => $copies[1]], $this->copies->ids($id));
    }

    public function testAWelcomeEmailWaitsForMoreAndCattoMailIsReadBack(): void
    {
        $list = $this->lists->createOwn('Early access', '', true);
        $welcome = $this->campaigns->create('welcome', 'Welcome', '', ['list_ids' => [$list], 'status' => 'sending']);
        $this->consents->joinList('1', $list, 'join_link');
        $this->copies->add($welcome, [['account' => '1', 'email' => 'person1@example.test', 'list_id' => $list, 'reasons' => ['Early access']]]);
        $token = (string) ($this->copies->byId($this->copyIds($welcome)[0])['token'] ?? '');
        $http = new MockHttpClient(function (string $method, string $url) use ($token): MockResponse {
            if (str_ends_with($url, '/send-jobs')) return new MockResponse((string) json_encode(['id' => 'job-1']), ['http_code' => 201]);
            if (str_contains($url, '/messages')) return new MockResponse((string) json_encode(['data' => [['id' => 'm1', 'external_recipient_reference' => $token, 'current_status' => 'remote_accepted']], 'pagination' => ['next_cursor' => null]]), ['http_code' => 200]);

            return new MockResponse('{}', ['http_code' => 201]);
        });
        $site = new Foundations(['CATTOMAIL_API_BASE_URL' => 'https://mail.example.test', 'CATTOMAIL_API_KEY' => 'shk_x', 'MAILING_TRANSPORT' => 'cattomail'], settingsProviders: [new MailingSettingGroup()]);
        $settings = new MailingSettings($site->settings);
        $sender = $this->sender(new MailingTransports($settings, $this->transport(), new CattoMailTransport(new CattoMailClient($http, $settings), 'example')));
        self::assertSame(1, $sender->run()['handed']);
        self::assertSame('sending', $this->campaigns->find($welcome)['status'] ?? null, 'A welcome email waits for the next person.');
        self::assertSame('job-1', $this->copies->byId($this->copyIds($welcome)[0])['transport_job_id'] ?? null);
        self::assertSame(1, $this->copies->cattomailJobs(10)[0]['copies'] ?? null);
        $this->site->clock->modify('+20 minutes');
        self::assertSame(1, $sender->run()['resolved'], 'Read back from catto-mail.');
        self::assertSame('delivered', $this->copies->byId($this->copyIds($welcome)[0])['status'] ?? null);

        $copy = (array) $this->copies->byId($this->copyIds($welcome)[0]);
        $events = new CattoMailEvents($this->connection, [new CopyEvents($this->copies, $this->outcomes)]);
        $events->receive('evt-1', 'message.complained', (string) json_encode(['id' => 'evt-1', 'type' => 'message.complained', 'data' => ['message' => ['id' => 'm1', 'external_recipient_reference' => $copy['token']], 'event' => ['reason' => 'abuse']]]));
        $events->receive('evt-2', 'send.completed', (string) json_encode(['id' => 'evt-2', 'type' => 'send.completed', 'data' => ['id' => 'job-1']]));
        $events->receive('evt-3', 'message.hard_bounced', (string) json_encode(['id' => 'evt-3', 'type' => 'message.hard_bounced', 'data' => ['message' => ['external_recipient_reference' => str_repeat('0', 32)]]]));
        self::assertSame(3, $events->process());
        self::assertSame(['No copy here has that reference.', 'Its copies are read back on the next run.', 'Recorded: a spam complaint.'], array_column($events->recent(), 'outcome'));
        self::assertSame(['complained', 'abuse'], [$this->copies->byId((int) $copy['id'])['status'] ?? null, $this->copies->byId((int) $copy['id'])['status_detail'] ?? null]);

        $links = new Links($this->connection);
        $link = $links->id($welcome, 'https://example.test/courses');
        self::assertSame($link, $links->id($welcome, 'https://example.test/courses'), 'Kept once.');
        self::assertSame('https://example.test/courses', $links->url($welcome, $link));
        self::assertNull($links->url($welcome + 1, $link));
        $links->recordClick((int) $copy['id'], $link);
        $links->recordClick((int) $copy['id'], $link);
        $this->copies->recordOpen((int) $copy['id']);
        self::assertSame([['url' => 'https://example.test/courses', 'clicks' => 2, 'people' => 1]], $links->figures($welcome));
        $figures = $this->campaigns->figures($welcome);
        self::assertSame([1, 1, 1], [$figures['opened'], $figures['clicked'], $figures['via_cattomail']]);
        $this->copies->setContent((int) $copy['id'], ['kept' => '2026-11-09']);
        self::assertSame(['kept' => '2026-11-09'], $this->copies->firstContent($welcome));
        $this->copies->forget('1');
        self::assertNull($this->copies->byId((int) $copy['id'])['account'] ?? null, 'Their account gone, the copy stays without them.');
        self::assertSame(1, $this->copies->deleteBefore(new \DateTimeImmutable('+1 day')));
        self::assertNull($this->connection->fetchOne("SELECT copy_id FROM cattophp_mailing_suppressions WHERE email = 'person1@example.test'"), 'An address never emailed forgets a copy deleted.');
    }

    public function testTheLinksInACopyAndForwardingItToAFriend(): void
    {
        $news = (int) $this->consents->subscribe('1', 'news', null, 'registration');
        $id = $this->campaigns->create('message', 'Monthly news', '', ['subject' => 'News', 'list_ids' => [$news], 'status' => 'sent']);
        $this->copies->add($id, [['account' => '1', 'email' => 'person1@example.test', 'list_id' => $news, 'reasons' => ['News and Updates']],
            ['account' => '2', 'email' => 'person2@example.test', 'list_id' => $news, 'reasons' => ['News and Updates']]]);
        [$first, $second] = $this->copyIds($id);
        $this->copies->update($first, ['status' => 'handed', 'handed_at' => '2026-11-09 10:00:00+00']);
        $token = (string) ($this->copies->byId($first)['token'] ?? '');
        $queued = (string) ($this->copies->byId($second)['token'] ?? '');
        $project = $this->project();
        $links = new \CattoPHP\Mailing\CopyLinks($this->copies, new Links($this->connection), $this->campaigns, $this->subscriptions, $project);

        $links->opened($token);
        $link = (new Links($this->connection))->id($id, 'https://example.test/courses');
        self::assertSame('https://example.test/courses', $links->clicked($token, $link));
        self::assertNull($links->clicked($token, $link + 1), 'A link the copy does not have.');
        self::assertNull($links->clicked(str_repeat('0', 32), $link));
        self::assertSame([1, 1], [$this->copies->byId($first)['open_count'] ?? null, $this->copies->byId($first)['click_count'] ?? null]);
        $this->rules = new SendingRules(trackOpens: false, trackClicks: false);
        $links->opened($token);
        self::assertSame('https://example.test/courses', $links->clicked($token, $link), 'Not recorded, still followed.');
        self::assertSame([1, 1], [$this->copies->byId($first)['open_count'] ?? null, $this->copies->byId($first)['click_count'] ?? null]);
        self::assertSame($first, (int) ($links->sent($token)['copy']['id'] ?? 0));
        self::assertNull($links->sent($queued), 'Not sent yet: no web copy, no forwarding.');
        $copy = $this->copies->byId($first) ?? [];
        self::assertFalse($links->stopped($copy));
        $this->outcomes->unsubscribe($copy, false, 'one_click');
        self::assertTrue($links->stopped($this->copies->byId($first) ?? []));
        $this->consents->subscribe('1', 'news', null, 'preferences');
        self::assertFalse($links->stopped($this->copies->byId($first) ?? []), 'Followed again: the copy asks again.');

        $forwards = new \CattoPHP\Mailing\Forwards($this->connection, $this->never, $this->site->mailer, $project, $this->site->activity);
        $campaign = $this->campaigns->find($id) ?? [];
        self::assertSame('thandi@example.test', $forwards->forward($campaign, $copy, ' Thandi@Example.TEST ', 'Thandi Mokoena', 'You will like this.'));
        $sent = $this->site->transport->last();
        self::assertSame(['thandi@example.test', 'Thandi Mokoena', '[DEMO] Fwd: News for Thandi Mokoena'], [$sent->getTo()[0]->getAddress(), $sent->getTo()[0]->getName(), $sent->getSubject()], 'Through the site\'s own mail, which marks a demo site\'s emails.');
        $friend = (string) $this->connection->fetchOne("SELECT token FROM cattophp_mailing_forwards WHERE to_email = 'thandi@example.test'");
        self::assertStringContainsString('You will like this. https://example.test/m/n/' . $friend, (string) $sent->getTextBody(), 'Drawn for the friend with the note and their Never email me.');
        self::assertSame('mailing.forwarded', $this->site->activity->recent()[0]->action);
        foreach (['person1@example.test' => 'That is your own address.', 'thandi@example.test' => 'You already forwarded this email to thandi@example.test.', 'not-an-address' => 'Write your friend'] as $to => $why) {
            try {
                $forwards->forward($campaign, $copy, $to, '', '');
                self::fail('Forwarded to ' . $to . '.');
            } catch (\InvalidArgumentException $refused) {
                self::assertStringStartsWith($why, $refused->getMessage());
            }
        }
        for ($i = 2; $i <= 5; $i++) $forwards->forward($campaign, $copy, 'friend' . $i . '@example.test', '', '');
        try {
            $forwards->forward($campaign, $copy, 'friend6@example.test', '', '');
            self::fail('Forwarded to a sixth friend.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame('This email has been forwarded to 5 friends already, the most it can be.', $refused->getMessage());
        }
        self::assertSame([5, 5, 5], [$forwards->countForCopy($first), $forwards->countForCampaign($id), $forwards->countForAccountToday('1')]);
        self::assertSame('thandi@example.test', $forwards->emailByToken($friend));
        self::assertNull($forwards->emailByToken('nope'));
        self::assertSame('thandi@example.test', $forwards->neverEmail($friend));
        self::assertTrue($this->never->isSuppressed('thandi@example.test'), 'Never email me: never emailed.');
        $forwards->forget('1');
        self::assertSame(0, $forwards->countForCampaign($id));
    }

    /** @return list<int> the copies of a campaign, oldest first */
    private function copyIds(int $campaign): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM cattophp_mailing_copies WHERE campaign_id = :c ORDER BY id', ['c' => $campaign]));
    }

    private function transport(): MailingTransport
    {
        return new class ($this) implements MailingTransport {
            public function __construct(private readonly CampaignsTest $test)
            {
            }

            public function name(): string
            {
                return 'smtp';
            }

            public function send(string $mailing, string $kind, SenderIdentity $sender, array $copies): SendResult
            {
                if ($this->test->refuse !== null) throw $this->test->refuse;
                $this->test->sent[] = ['mailing' => $mailing, 'kind' => $kind, 'copies' => $copies];
                $handed = [];
                foreach ($copies as $copy) $handed[$copy->id] = ['job_id' => null, 'message_id' => 'smtp-' . $copy->id];

                return new SendResult($handed);
            }
        };
    }

    private function sender(?MailingTransports $transports = null): CampaignSender
    {
        $settings = new MailingSettings($this->site->settings);
        $transports ??= new MailingTransports($settings, $this->transport(), new CattoMailTransport(new CattoMailClient(new MockHttpClient(), $settings), 'example'));
        $project = $this->project();

        return new CampaignSender($this->campaigns, $this->copies, $transports, $this->outcomes, $this->subscriptions, $this->never, $project, $this->connection, $this->site->clock);
    }

    private function project(): CampaignProject
    {
        return new class ($this) implements CampaignProject {
            public function __construct(private readonly CampaignsTest $test)
            {
            }

            public function audience(array $campaign): ?array
            {
                if ($campaign['kind'] !== 'message') return null;
                $people = [];
                foreach ((array) $campaign['list_ids'] as $list) {
                    foreach ($this->test->onList((int) $list) as $account) {
                        $people[] = ['account' => $account, 'email' => 'person' . $account . '@example.test', 'list_id' => (int) $list, 'reasons' => ['News and Updates']];
                    }
                }

                return $people;
            }

            public function sender(array $campaign): SenderIdentity
            {
                return new SenderIdentity('news@example.test', 'Example News');
            }

            public function draw(array $campaign, array $copy): DrawnCopy
            {
                return new DrawnCopy($campaign['subject'] . ' for person' . $copy['account'], '<p>Hi</p>', 'Hi', 'https://example.test/m/u/' . $copy['token'], 'News and Updates');
            }

            public function drawForFriend(array $campaign, array $copy, string $friendName, string $note, string $token): DrawnCopy
            {
                return new DrawnCopy('Fwd: ' . $campaign['subject'] . ($friendName !== '' ? ' for ' . $friendName : ''), '<p>' . htmlspecialchars($note) . '</p>', trim($note . ' https://example.test/m/n/' . $token), '', '');
            }

            public function waitsForMore(array $campaign): bool
            {
                return $campaign['kind'] === 'welcome';
            }

            public function rules(): SendingRules
            {
                return $this->test->rules;
            }
        };
    }

    /** @return list<string> the accounts on a list, by their number */
    public function onList(int $list): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn("SELECT account FROM cattophp_mailing_subscriptions WHERE list_id = :l AND status = 'subscribed' ORDER BY CAST(account AS INT)", ['l' => $list]));
    }
}
