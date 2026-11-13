<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Http\FormStamp;
use CattoPHP\Inbox\ImapMailbox;
use CattoPHP\Inbox\Inbox;
use CattoPHP\Inbox\InboxDocuments;
use CattoPHP\Inbox\InboxMailbox;
use CattoPHP\Inbox\InboxReplies;
use CattoPHP\Inbox\InboxSettingGroup;
use CattoPHP\Inbox\InboxSettings;
use CattoPHP\Inbox\InboxStore;
use CattoPHP\Inbox\MimeMessage;
use CattoPHP\Inbox\ReplySender;
use CattoPHP\Signup\MailDomainCheck;
use CattoPHP\Signup\SignupCountries;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mime\Email;

/** The Inbox's conversations, spam check, folders, replies and answers, ported from cattolms's Inbox and contact message tests. */
final class InboxTest extends DatabaseTestCase
{
    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:146.0) Gecko/20100101 Firefox/146.0';

    private Foundations $site;
    private InboxStore $store;
    private InboxSettings $settings;
    private Inbox $inbox;
    private FormStamp $stamps;
    private string $folder;
    /** @var list<Email> */
    public array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = new Foundations(['MAIL_FROM_ADDRESS' => 'site@example.com', 'MAIL_REPLY_TO' => 'help@example.com'], settingsProviders: [new InboxSettingGroup()]);
        $this->store = new InboxStore($this->connection);
        $this->settings = new InboxSettings($this->site->settings);
        $this->stamps = new FormStamp(Foundations::KEY);
        $requests = new RequestStack();
        $requests->push(new Request(server: ['REMOTE_ADDR' => '198.51.100.20', 'HTTP_USER_AGENT' => self::BROWSER]));
        $mail = new class extends MailDomainCheck {
            public function receivesMail(string $domain): ?bool
            {
                return true;
            }
        };
        $this->inbox = new Inbox($this->store, $this->settings, $this->stamps, mail: $mail, countries: new SignupCountries($this->site->settings), activity: $this->site->activity, requests: $requests);
        $this->folder = Foundations::folder();
    }

    protected function tearDown(): void
    {
        Foundations::remove($this->folder);
        parent::tearDown();
    }

    public function testAMessageIsKeptJudgedAndFiledWithItsNotices(): void
    {
        [$spamReference, $spam] = $this->send('Rank Booster Pro', 'offers@example.net',
            'Get your website to page one of Google in 7 days! Guaranteed SEO and backlinks. Click here: https://a.example https://b.example https://c.example https://d.example', 1);
        self::assertMatchesRegularExpression('/^MSG-\d{6}$/', $spamReference);
        $held = $this->store->conversation($spam) ?? [];
        self::assertSame('spam', $held['folder']);
        self::assertGreaterThanOrEqual(60, $held['spam_score']);
        self::assertContains('4 links', array_column(json_decode((string) $held['spam_signals'], true), 'words'));
        self::assertSame(['198.51.100.20', 1, self::BROWSER], [$held['sender_ip'], $held['seconds_on_page'], $held['sender_agent']], 'How it was sent, from the request.');
        [$reference, $genuine] = $this->send('Thandeka Mokoena', 'thandeka@example.org', 'My receipt shows my old home address. Could you send one with my company address?', 120);
        $this->inbox->emailed($genuine);
        $this->inbox->acknowledged($genuine, 'thandeka@example.org', $reference);
        $arrived = $this->store->conversation($genuine) ?? [];
        self::assertSame('inbox', $arrived['folder']);
        self::assertNotNull($arrived['emailed_at']);
        self::assertNotNull($arrived['acknowledged_at']);
        self::assertSame(['message', 'acknowledgement'], array_column($this->store->itemsOf($genuine), 'kind'));
        self::assertSame('We received your message ' . $reference, $this->store->itemsOf($genuine)[1]['subject']);
        self::assertSame(['new' => 1, 'drafts' => 0, 'spam' => 1, 'trash' => 0], $this->inbox->counts());
        self::assertSame($genuine, $this->inbox->idByReference($reference));
        $this->inbox->markRead($genuine);
        self::assertSame(0, $this->inbox->counts()['new']);
    }

    public function testMarkingTeachesTheFilterAndChangingTheVerdictTakesItBack(): void
    {
        [, $id] = $this->send('Lerato Khumalo', 'lerato@example.org', 'Unbeatable zorbifuzz offer on quantumwidgets today', 120);
        $this->inbox->markSpam([$id], '7');
        self::assertSame('spam', ($this->store->conversation($id) ?? [])['folder']);
        self::assertSame(['spam' => 1, 'ham' => 0], $this->inbox->trained());
        self::assertSame([1, 0], $this->counts('zorbifuzz'));
        $this->inbox->markSpam([$id], '7');
        self::assertSame([1, 0], $this->counts('zorbifuzz'), 'Marked twice, learnt once.');
        $this->inbox->notSpam([$id], '7');
        self::assertSame(['inbox', 'ham'], [($this->store->conversation($id) ?? [])['folder'], ($this->store->conversation($id) ?? [])['spam_verdict']]);
        self::assertSame([0, 1], $this->counts('zorbifuzz'), 'The spam count is taken back.');
        self::assertSame(['message_id' => $id, 'reference' => ($this->store->conversation($id) ?? [])['reference']], $this->site->activityStore->recent(1, 'inbox.marked_not_spam')[0]->details);
        self::assertSame('7', $this->site->activityStore->recent(1, 'inbox.marked_not_spam')[0]->actorId);
        self::assertSame('lerato@example.org', $this->inbox->blockSender($id, 'email', '7'));
        self::assertSame('spam', ($this->store->conversation($id) ?? [])['folder'], 'Blocking the sender sends it to Spam.');
        [, $later] = $this->send('Lerato Khumalo', 'lerato@example.org', 'Hello again, a plain question about a course.', 120);
        self::assertSame('spam', ($this->store->conversation($later) ?? [])['folder'], 'A blocked sender\'s later message goes straight to Spam.');
        $this->inbox->forget('7');
        self::assertSame(['spam' => 0, 'ham' => 0], $this->inbox->trained());
        $this->site->settings->set('INBOX_NEVER_SPAM', 'example.org');
        [, $never] = $this->send('Lerato Khumalo', 'lerato@example.org', 'Once more.', 120);
        self::assertSame('inbox', ($this->store->conversation($never) ?? [])['folder'], 'Never sent to Spam, blocked or not.');
        $this->expectException(InvalidArgumentException::class);
        $this->inbox->blockSender(999999, 'email', '7');
    }

    public function testTrashRestoreDeleteForeverAndTheDailyCleanUp(): void
    {
        [, $id] = $this->send('Sipho Nkosi', 'sipho@example.org', 'Test message, please delete.', 120);
        self::assertSame(1, $this->inbox->trash([$id], '7'));
        self::assertSame(['trash', 'inbox'], [($this->store->conversation($id) ?? [])['folder'], ($this->store->conversation($id) ?? [])['folder_before_trash']]);
        self::assertSame(1, $this->inbox->deleteForever([$id], '7'));
        self::assertNull($this->store->conversation($id), 'Delete forever in the Trash removes it for good.');
        [, $kept] = $this->send('Ayesha Patel', 'ayesha@example.org', 'The video in Module 3 no longer plays.', 120);
        $this->inbox->trash([$kept], '7');
        self::assertSame(1, $this->inbox->restore([$kept], '7'));
        self::assertSame('inbox', ($this->store->conversation($kept) ?? [])['folder'], 'Restore puts it back where it was.');
        self::assertSame(0, $this->inbox->deleteForever([$kept], '7'), 'Delete forever never touches the Inbox.');
        $this->inbox->markAnswered($kept, '7');
        $this->inbox->markAnswered($kept, '8');
        self::assertSame('7', ($this->store->conversation($kept) ?? [])['answered_by'], 'Answered once.');
        $this->inbox->markUnanswered($kept, '7');
        self::assertNull(($this->store->conversation($kept) ?? [])['answered_at']);
        self::assertSame(['contact.message_reopened', 'contact.message_answered'], array_map(static fn($entry): string => $entry->action, $this->site->activityStore->recent(2)));
        $this->inbox->trash([$kept], '7');
        $this->connection->executeStatement("UPDATE cattophp_inbox_conversations SET trashed_at = now() - interval '31 days' WHERE id = :id", ['id' => $kept]);
        [, $old] = $this->send('Ben Carter', 'ben@example.org', 'An old question.', 120);
        $this->connection->executeStatement("UPDATE cattophp_inbox_conversations SET last_activity_at = now() - interval '6 years' WHERE id = :id", ['id' => $old]);
        self::assertSame(['trash' => 1, 'spam' => 0, 'old' => 1], $this->inbox->cleanUp());
        self::assertSame(['trash' => '30 days', 'spam' => '30 days', 'line' => 60], $this->inbox->keeping());
        self::assertSame('in 29 days', $this->inbox->goneFor(date(DATE_ATOM, time() - 3600), $this->settings->trashKeptFor()));
        self::assertSame('tonight', $this->inbox->goneFor(date(DATE_ATOM, time() - 30 * 86400 + 60), $this->settings->trashKeptFor()));
    }

    public function testAReplyIsDraftedSentAndThreadedAndADiscardedDraftWaitsInTheTrash(): void
    {
        [$reference, $id] = $this->send('Johan van Wyk', 'johan@example.org', 'I changed my email address and now my company says I left.', 120, '12');
        $replies = $this->replies();
        $compose = $replies->compose(($this->store->conversation($id) ?? []) + ['reason_label' => 'Other']);
        self::assertSame('Re: Other [' . $reference . ']', $compose['subject'], 'The subject carries the reference an answer is matched by.');
        self::assertSame(['Example Site <site@example.com>', 'help@example.com', 'html'], [$compose['from'], $compose['reply_to'], $compose['format']]);
        self::assertSame(['Invoice INV-0042'], array_column($compose['documents'], 'label'), 'The account\'s own documents may be attached.');
        $file = tempnam(sys_get_temp_dir(), 'inbox');
        file_put_contents((string) $file, 'Steps to keep your account.');
        $upload = new UploadedFile((string) $file, 'steps.txt', 'text/plain', null, true);
        $body = '<h2>Good morning</h2><p>Your membership follows your <strong>primary</strong> address.</p><script>alert(1)</script>';
        $draft = $replies->save($id, ['format' => 'html', 'subject' => $compose['subject'], 'body_html' => $body, 'mark_answered' => '1', 'documents' => ['42', '99']], [$upload], '7', false);
        self::assertFalse($draft['sent']);
        self::assertCount(0, $this->sent, 'A draft sends nothing.');
        self::assertSame(1, $this->inbox->counts()['drafts']);
        self::assertSame($draft['item_id'], $replies->compose(($this->store->conversation($id) ?? []) + ['reason_label' => 'Other'])['draft_id'], 'It is the conversation\'s draft.');
        $sent = $replies->save($id, ['format' => 'html', 'subject' => $compose['subject'], 'body_html' => $body, 'mark_answered' => '1', 'documents' => ['42']], [], '7', true);
        self::assertTrue($sent['sent'], $sent['error']);
        $mail = $this->sent[0] ?? null;
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('johan@example.org', $mail->getTo()[0]->getAddress());
        self::assertStringContainsString('<strong>primary</strong>', (string) $mail->getHtmlBody());
        self::assertStringNotContainsString('<script', (string) $mail->getHtmlBody(), 'The reply keeps what the editor writes and nothing else.');
        self::assertStringContainsString('Good morning', (string) $mail->getTextBody(), 'An HTML reply carries a plain text copy.');
        self::assertSame(['steps.txt', 'INV-0042.pdf'], array_map(static fn($part): string => (string) $part->getFilename(), $mail->getAttachments()), 'The draft\'s file and the document go with it.');
        $messageId = (string) $mail->getHeaders()->get('Message-ID')?->getBodyAsString();
        self::assertMatchesRegularExpression('/^<inbox\.[0-9a-f-]{36}@example\.com>$/', $messageId);
        self::assertNotNull(($this->store->conversation($id) ?? [])['answered_at'], 'Sent with the tick, it marks the message answered.');
        self::assertSame(['message_id' => $id, 'reference' => $reference, 'format' => 'html', 'attachments' => 2], $this->site->activityStore->recent(1, 'inbox.reply_sent')[0]->details);
        self::assertSame($id, $this->store->conversationFor(trim($messageId, '<>'), 'Re: anything'), 'An answer naming it threads into the conversation.');
        self::assertSame($id, $this->store->conversationFor(null, 'RE: Re: Other [' . strtolower($reference) . ']'), 'Or by the reference in its subject.');
        $item = $this->store->item($sent['item_id']) ?? [];
        self::assertSame(['Steps to keep your account.', 'steps.txt', 'text/plain'], $replies->download($id, (string) $item['public_id'], 0));
        self::assertSame(['%PDF-1.4 INV-0042', 'INV-0042.pdf', 'application/pdf'], $replies->download($id, (string) $item['public_id'], 1));
        self::assertNull($replies->download($id, (string) $item['public_id'], 5));
        $replies->save($id, ['format' => 'plain', 'subject' => 'A second thought', 'body_text' => 'One more thing.'], [], '7', false);
        self::assertTrue($replies->discard($id, '7'));
        self::assertFalse($replies->discard($id, '7'), 'Nothing left to discard.');
        self::assertSame(1, $this->inbox->counts()['trash'], 'A discarded draft waits in the Trash.');
        $trashed = (int) $this->connection->fetchOne('SELECT id FROM cattophp_inbox_items WHERE trashed_at IS NOT NULL');
        self::assertSame(1, $this->inbox->restoreDrafts([$trashed], '7'));
        self::assertSame('One more thing.', trim((string) $replies->compose(($this->store->conversation($id) ?? []) + ['reason_label' => 'Other'])['body_text']), 'Restored, it is the draft being written again.');
        try {
            $replies->save($id, ['format' => 'plain', 'subject' => ' ', 'body_text' => 'x'], [], '7', true);
            self::fail('A reply without a subject was sent.');
        } catch (InvalidArgumentException $refused) {
            self::assertSame('Give the reply a subject.', $refused->getMessage());
        }
        $this->expectExceptionMessage('The reply is empty: write it before sending.');
        $replies->save($id, ['format' => 'plain', 'subject' => 'Nothing', 'body_text' => '  '], [], '7', true);
    }

    public function testAnAnswerFromTheMailboxIsFiledIntoItsConversationOnce(): void
    {
        [$reference, $id] = $this->send('Pieter Joubert', 'pieter@example.org', 'Does the course cover VAT returns?', 120);
        $sent = $this->replies()->save($id, ['format' => 'plain', 'subject' => 'Re: Other [' . $reference . ']', 'body_text' => 'VAT returns come in a later course.', 'mark_answered' => '1'], [], '7', true);
        $messageId = (string) ($this->store->item($sent['item_id']) ?? [])['message_id'];
        $this->inbox->trash([$id], '7');
        $mailbox = new InboxMailbox($this->settings, $this->store);
        $answer = MimeMessage::parse("From: Pieter Joubert <pieter@example.org>\nSubject: Re: something else\nMessage-ID: <answer@example.org>\nIn-Reply-To: <{$messageId}>\n\nWhich course is it?");
        self::assertTrue($mailbox->file($answer), 'Matched by the email it answers.');
        self::assertFalse($mailbox->file($answer), 'Filed once.');
        $conversation = $this->store->conversation($id) ?? [];
        self::assertSame(['inbox', null, true], [$conversation['folder'], $conversation['answered_at'], $conversation['unread_answer']], 'Out of the Trash, and not answered until replied to.');
        $items = $this->store->itemsOf($id);
        self::assertSame('Which course is it?', $items[count($items) - 1]['body_text'] ?? null);
        self::assertTrue($mailbox->file(MimeMessage::parse("From: pieter@example.org\nSubject: RE: Re: Other [{$reference}]\nMessage-ID: <second@example.org>\n\nThanks.")), 'Or by the reference in its subject.');
        self::assertFalse($mailbox->file(MimeMessage::parse("From: pieter@example.org\nAuto-Submitted: auto-replied\nSubject: Re: Other [{$reference}]\n\nI am away.")), 'An automatic reply is left alone.');
        self::assertFalse($mailbox->file(MimeMessage::parse("From: someone@example.org\nSubject: Newsletter\n\nHello.")), 'Mail answering no conversation is left alone.');
        self::assertSame('No mailbox is set in Inbox settings.', $mailbox->run());
        $this->store->saveMailboxState(['validity' => 1730000000, 'uid' => 42, 'checked_at' => '2026-11-12T16:51:00+02:00', 'error' => '']);
        self::assertSame(['state' => 'none', 'checked_at' => '2026-11-12T16:51:00+02:00', 'error' => ''], $mailbox->status());
        $mailbox->forget();
        self::assertSame(['state' => 'none', 'checked_at' => '', 'error' => ''], $mailbox->status());
    }

    public function testRowsWrittenBeforeReadTheSame(): void
    {
        // As a project's upgrade migration writes what it kept: the sender's account in text, the project's own fields as details.
        $this->connection->executeStatement("INSERT INTO cattophp_inbox_conversations (id, reference, reason, sender_name, sender_email, account, account_reference, details, message, created_at, answered_at, answered_by, folder, read_at, last_activity_at)
            VALUES (43, 'MSG-000043', 'order', 'Zodwa Kleynhans', 'zodwa@example.org', '12', 'CL2026102714192O', '{\"topic\":\"refund\"}', 'Please send the credit note again.', now() - interval '1 day', now(), '1', 'inbox', now(), now())");
        $this->connection->executeStatement("SELECT setval(pg_get_serial_sequence('cattophp_inbox_conversations', 'id'), 43)");
        $this->connection->executeStatement("INSERT INTO cattophp_inbox_items (conversation_id, direction, kind, status, from_name, from_email, body_text, received_at) VALUES (43, 'received', 'message', 'received', 'Zodwa Kleynhans', 'zodwa@example.org', 'Please send the credit note again.', now() - interval '1 day')");
        $kept = $this->store->conversation(43) ?? [];
        self::assertSame(['MSG-000043', '12', 'CL2026102714192O', '{"topic": "refund"}', '1'], [$kept['reference'], $kept['account'], $kept['account_reference'], $kept['details'], $kept['answered_by']]);
        self::assertSame(['message'], array_column($this->store->itemsOf(43), 'kind'));
        self::assertSame(43, $this->inbox->idByReference('MSG-000043'));
        self::assertSame('MSG-000044', $this->inbox->keep(['reason' => 'other', 'sender_name' => 'Next', 'sender_email' => 'next@example.org', 'message' => 'Next.'])['reference'], 'The numbers go on from where they were.');
    }

    public function testTheMailboxShowsTheOutcomeOfItsLastTry(): void
    {
        $server = new class extends ImapMailbox {
            public bool $accepts = false;
            public int $reads = 0;

            public function check(array $mailbox): string
            {
                if (!$this->accepts) throw new RuntimeException('The mail server did not accept the mailbox user and password.');

                return 'Connected: 3 messages in the mailbox.';
            }

            public function newMessages(array $mailbox, array $state): array
            {
                $this->reads++;
                if (!$this->accepts) throw new RuntimeException('The mail server did not accept the mailbox user and password.');

                return ['messages' => [], 'state' => ['validity' => 7, 'last' => 3]];
            }
        };
        $mailbox = new InboxMailbox($this->settings, $this->store, $server);
        self::assertSame('none', $mailbox->status()['state']);
        $this->settings->saveAll(['inbox_imap_host' => 'imap.example.com', 'inbox_imap_user' => 'answers@example.com', 'inbox_imap_password' => 'not-made-yet'], null);
        self::assertSame('unchecked', $mailbox->status()['state'], 'Set but never tried: not connected.');
        self::assertSame('The mailbox is not connected: Check the connection in Inbox settings connects it.', $mailbox->run());
        self::assertSame(0, $server->reads, 'Details never checked are not read.');
        try {
            $mailbox->check();
            self::fail('A refused sign-in is an error.');
        } catch (RuntimeException $refused) {
            self::assertSame('The mail server did not accept the mailbox user and password.', $refused->getMessage());
        }
        $status = $mailbox->status();
        self::assertSame(['failed', 'The mail server did not accept the mailbox user and password.'], [$status['state'], $status['error']], 'A failed check shows as failed, never as connected.');
        self::assertNotSame('', $status['checked_at']);
        $mailbox->run();
        self::assertSame(0, $server->reads, 'A mailbox that refused is not tried again every minute: a connection exists only once a real try made it.');
        $before = $this->settings->mailbox();
        $mailbox->detailsSaved($before);
        self::assertSame('failed', $mailbox->status()['state'], 'Saved with the same details: as it was.');
        $this->settings->saveAll(['inbox_imap_host' => 'imap.example.com', 'inbox_imap_user' => 'answers@example.com', 'inbox_imap_password' => 'made-now'], null);
        $mailbox->detailsSaved($before);
        self::assertSame(['unchecked', '', ''], array_values($mailbox->status()), 'New details: not checked yet.');
        $server->accepts = true;
        self::assertSame('Connected: 3 messages in the mailbox.', $mailbox->check());
        self::assertSame('connected', $mailbox->status()['state']);
        $mailbox->run();
        self::assertSame(1, $server->reads, 'Connected: read.');
        $server->accepts = false;
        $mailbox->run();
        self::assertSame([2, 'failed'], [$server->reads, $mailbox->status()['state']], 'A read that fails later shows too.');
        $server->accepts = true;
        $mailbox->run();
        self::assertSame(2, $server->reads, 'And it is not read again until a check works.');
        $mailbox->check();
        $mailbox->run();
        self::assertSame([3, 'connected', ''], [$server->reads, $mailbox->status()['state'], $mailbox->status()['error']]);
        $this->settings->forgetMailbox();
        self::assertSame('none', $mailbox->status()['state']);
    }

    public function testTheSettingsAreReadSavedAndReset(): void
    {
        self::assertSame(['30 days', 60, 'html', false], [$this->settings->trashKeptFor()->words(), $this->settings->spamLine(), $this->settings->replyFormat(), $this->settings->mailboxSet()]);
        $this->settings->saveAll(['inbox_trash_kept_for' => '2w', 'inbox_spam_line' => '70', 'inbox_reply_format' => 'plain', 'inbox_learn' => 'on', 'inbox_notify_email' => 'on', 'inbox_acknowledge' => 'on',
            'inbox_hold_blocked_countries' => 'on', 'inbox_signature_text' => "Kind regards\nThe team", 'inbox_imap_host' => 'imap.example.com', 'inbox_imap_user' => 'inbox@example.com', 'inbox_imap_password' => 'secret-words'], null);
        self::assertSame(['2 weeks', 70, 'plain', false, true], [$this->settings->trashKeptFor()->words(), $this->settings->spamLine(), $this->settings->replyFormat(), $this->settings->notifiesByTelegram(), $this->settings->mailboxSet()], 'A tick left unticked is off.');
        self::assertSame("Kind regards\nThe team", $this->settings->signature('plain'));
        self::assertSame(['value' => '', 'overridden' => true, 'secret_set' => true], $this->settings->view()['INBOX_IMAP_PASSWORD'], 'The password is shown only as set.');
        $this->settings->saveAll(['inbox_imap_host' => 'imap.example.com', 'inbox_imap_user' => 'inbox@example.com', 'inbox_imap_password' => ''], null);
        self::assertTrue($this->settings->mailboxSet(), 'A password left blank keeps the one there is.');
        $this->settings->forgetMailbox();
        self::assertFalse($this->settings->mailboxSet());
        $this->settings->resetAll();
        self::assertSame(['30 days', 60], [$this->settings->trashKeptFor()->words(), $this->settings->spamLine()]);
    }

    /** @return array{0:string,1:int} a message sent through a contact form: its reference and id */
    private function send(string $name, string $email, string $message, int $secondsOpen, ?string $account = null): array
    {
        $kept = $this->inbox->keep(['reason' => 'other', 'sender_name' => $name, 'sender_email' => $email, 'account' => $account, 'message' => $message]);
        $this->inbox->arrived($kept['id'], $name, $email, $message, $account !== null, [Inbox::STAMP_FIELD => $this->stamps->stamp(time() - $secondsOpen)]);

        return [$kept['reference'], $kept['id']];
    }

    /** @return array{0:int,1:int} a word's [spam, genuine] counts */
    private function counts(string $word): array
    {
        $row = $this->connection->fetchAssociative('SELECT spam_count, ham_count FROM cattophp_inbox_spam_tokens WHERE token = :w', ['w' => $word]) ?: [];

        return [(int) ($row['spam_count'] ?? 0), (int) ($row['ham_count'] ?? 0)];
    }

    private function replies(): InboxReplies
    {
        $sender = new class ($this) implements ReplySender {
            public function __construct(private readonly InboxTest $test)
            {
            }

            public function send(Email $email): void
            {
                $this->test->sent[] = $email;
            }
        };
        $documents = new class implements InboxDocuments {
            public function of(string $account): array
            {
                return $account === '12' ? [['id' => 42, 'number' => 'INV-0042', 'kind' => 'Invoice', 'label' => 'Invoice INV-0042']] : [];
            }

            public function attachment(int $id): ?array
            {
                return $id === 42 ? ['%PDF-1.4 INV-0042', 'INV-0042.pdf', 'application/pdf'] : null;
            }
        };

        return new InboxReplies($this->store, $this->settings, $this->site->settings, $this->site->installation, $sender, $this->folder, $documents, $this->site->activity);
    }
}
