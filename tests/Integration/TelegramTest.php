<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Activity\Actor;
use CattoPHP\Links\DbalLinkStore;
use CattoPHP\Runtime\SiteMode;
use CattoPHP\Telegram\OwnerMessages;
use CattoPHP\Telegram\OwnerMessageSender;
use CattoPHP\Telegram\TelegramAccounts;
use CattoPHP\Telegram\TelegramBot;
use CattoPHP\Telegram\TelegramClient;
use CattoPHP\Telegram\TelegramConnections;
use CattoPHP\Telegram\TelegramFailed;
use CattoPHP\Telegram\TelegramInbox;
use CattoPHP\Telegram\TelegramLog;
use CattoPHP\Telegram\TelegramMessenger;
use CattoPHP\Telegram\TelegramSetup;
use CattoPHP\Telegram\TelegramState;
use CattoPHP\Telegram\TelegramWords;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\TelegramStandIn;
use InvalidArgumentException;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** The Telegram module: messages to the owner, the bot's connection, people's accounts and what they send the bot, ported from cattolms's tests. */
final class TelegramTest extends DatabaseTestCase
{
    private const OWNER_CHAT = '4242';

    private Foundations $site;
    private TelegramStandIn $telegram;
    private TelegramState $state;
    private TelegramBot $bot;
    private TelegramLog $log;
    private TelegramAccounts $accounts;
    private TelegramMessenger $messenger;
    private TelegramConnections $connections;
    private TelegramInbox $inbox;
    private NativeClock $clock;
    private bool $telegramDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = new Foundations();
        $this->telegram = new TelegramStandIn();
        $this->clock = new NativeClock();
        $this->state = new TelegramState($this->connection);
        $this->bot = new TelegramBot($this->state, $this->site->cipher);
        $this->log = new TelegramLog($this->connection);
        $this->accounts = new TelegramAccounts($this->connection);
        $client = new TelegramClient($this->telegram->client());
        $this->messenger = new TelegramMessenger($this->bot, $this->log, $client);
        $this->connections = new TelegramConnections(new DbalLinkStore($this->connection), $this->accounts, $this->bot, $this->messenger, $this->words(), $this->connection, $this->site->cipher, $this->clock, $this->site->activity);
        $this->inbox = new TelegramInbox($this->bot, $this->state, $client, $this->accounts, $this->connections, $this->log, $this->clock);
        $this->bot->setKey(TelegramStandIn::KEY, TelegramStandIn::BOT, new Actor('1', 'Ada'));
        $this->bot->setSignIn(true);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testAMessageToTheOwnerIsKeptOnceInTheSitesWords(): void
    {
        $this->connectOwner();
        $this->owner()->queue('order:1042', 'sale', 'Sale: Order 1042, A course, R2,500, paid by EFT');
        $this->owner()->queue('order:1042', 'sale', 'A second with the same reference');
        $this->owner('demo')->queue('registration:7', 'registration', 'New registration: Thandi Mokoena, South Africa');
        self::assertSame(['Sale: Order 1042, A course, R2,500, paid by EFT (example.com)', '[DEMO] New registration: Thandi Mokoena, South Africa (example.com)'], $this->bodies());
        self::assertTrue($this->owner()->connected());
    }

    public function testNothingIsKeptWhileTheBotIsNotConnectedToTheOwner(): void
    {
        $this->owner()->queue('order:1', 'sale', 'Not kept: no chat yet');
        $this->connectOwner();
        $this->bot->forget();
        $this->owner()->queue('order:2', 'sale', 'Not kept: disconnected');
        self::assertSame([], $this->bodies());
    }

    public function testAFaultKeepingAMessageCannotFailWhatItIsAbout(): void
    {
        $this->connectOwner();
        $broken = new class ($this->connection) extends TelegramLog {
            public function __construct(private readonly \Doctrine\DBAL\Connection $db)
            {
                parent::__construct($db);
            }

            public function record(string $reference, string $kind, string $body): void
            {
                $this->db->executeStatement('INSERT INTO no_such_table_here (a) VALUES (1)');
            }
        };
        $this->connection->beginTransaction();
        $this->connection->executeStatement("INSERT INTO cattophp_telegram_state (name, value) VALUES ('address', 'https://telegram.example.com')");
        $logTo = (string) ini_set('error_log', '/dev/null');
        try {
            (new OwnerMessages($this->bot, $broken, $this->words()))->queue('order:1', 'sale', 'Never kept');
        } finally {
            ini_set('error_log', $logTo);
        }
        $this->connection->commit();
        self::assertSame('https://telegram.example.com', $this->state->get(TelegramState::ADDRESS), 'The transaction around it still works.');
    }

    public function testTheSenderSendsAndMarksEachMessageOnce(): void
    {
        $this->connectOwner();
        $this->log->record('registration:1', 'registration', 'New registration: Thandi Mokoena, South Africa');
        $this->log->record('order:2', 'sale', 'Sale: Order 2, Course A, R2,500, paid by EFT');
        $sender = $this->sender();
        self::assertSame('Sent 2 messages.', $sender->run());
        self::assertSame(['New registration: Thandi Mokoena, South Africa', 'Sale: Order 2, Course A, R2,500, paid by EFT'], $this->telegram->textsTo(self::OWNER_CHAT));
        self::assertSame(['is_disabled' => true], $this->telegram->message(0)['payload']['link_preview_options'], 'No preview may open a link.');
        self::assertSame('Nothing was waiting.', $sender->run(), 'Nothing goes twice.');
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM cattophp_telegram_messages WHERE sent_at IS NOT NULL AND tries = 1 AND to_username = 'your_username'"));
        self::assertNotNull($this->log->status()['last_sent_at']);
    }

    public function testATelegramFailureLeavesTheMessageWaitingAndItGoesNextTime(): void
    {
        $this->connectOwner();
        $this->log->record('order:1', 'sale', 'First');
        $this->log->record('order:2', 'sale', 'Second');
        $this->telegramDown = true;
        $tried = 0;
        $sender = new OwnerMessageSender($this->log, $this->bot, new TelegramClient(new MockHttpClient(function () use (&$tried): MockResponse {
            $tried++;

            return $this->telegramDown ? new MockResponse('', ['error' => 'Failed to connect to https://api.telegram.org/bot' . TelegramStandIn::KEY . '/sendMessage']) : new MockResponse('{"ok":true,"result":{}}');
        })), $this->clock);
        $summary = $sender->run();
        self::assertSame(1, $tried, 'It stops at the first failure, because the rest would fail too.');
        self::assertStringNotContainsString(TelegramStandIn::KEY, $summary);
        $row = $this->connection->fetchAssociative("SELECT tries, sent_at, last_error FROM cattophp_telegram_messages WHERE reference = 'order:1'") ?: [];
        self::assertSame([1, null], [(int) $row['tries'], $row['sent_at']], 'It waits.');
        self::assertStringContainsString('could not be reached', (string) $row['last_error']);
        self::assertStringNotContainsString(TelegramStandIn::KEY, (string) $row['last_error'], 'The key is never stored with an error.');
        self::assertSame(['failing', 2], [$this->setupPage()->view()['state'], $this->setupPage()->view()['waiting']]);
        self::assertStringNotContainsString(TelegramStandIn::KEY, (string) json_encode($this->setupPage()->view()));
        $this->telegramDown = false;
        self::assertSame('Sent 2 messages.', $sender->run());
        self::assertSame(['connected', null], [$this->setupPage()->view()['state'], $this->setupPage()->view()['last_error']], 'The page stops saying it failed.');
    }

    public function testOneUnsentAfterSevenDaysIsDroppedAndASentOneGoesWhenItsTimeIsUp(): void
    {
        $this->log->record('order:old', 'sale', 'Too old to matter');
        $this->log->record('order:new', 'sale', 'Still waiting');
        $this->log->record('order:sent', 'sale', 'Sent long ago');
        $this->connection->executeStatement("UPDATE cattophp_telegram_messages SET created_at = NOW() - INTERVAL '8 days' WHERE reference = 'order:old'");
        $this->connection->executeStatement("UPDATE cattophp_telegram_messages SET created_at = NOW() - INTERVAL '40 days', sent_at = NOW() - INTERVAL '31 days' WHERE reference = 'order:sent'");
        self::assertSame('Not connected to Telegram. Dropped 1 older than 7 days.', $this->sender()->run());
        self::assertSame(1, $this->log->deleteSentBefore(new \DateTimeImmutable('-30 days')));
        self::assertSame(['Still waiting'], $this->bodies());
    }

    public function testConnectingTheOwnerKeepsAKeyTelegramAcceptsAndNeverGivesItBack(): void
    {
        $this->bot->forget();
        $setup = $this->setupPage();
        foreach (['not a key', '123:short'] as $wrong) {
            $this->refusedWith('bot key', fn() => $setup->connect(['key' => $wrong, 'username' => 'your_username'], new Actor('1', 'Ada')));
        }
        $this->refusedWith('Paste the bot key.', fn() => $setup->connect(['key' => '', 'username' => 'your_username']));
        $other = '987654321:another-key-telegram-does-not-know';
        try {
            $setup->connect(['key' => $other, 'username' => 'your_username']);
            self::fail('A key Telegram refuses was saved.');
        } catch (TelegramFailed $refused) {
            self::assertStringNotContainsString($other, $refused->getMessage());
            self::assertSame(401, $refused->getCode());
        }
        self::assertSame([false, 'none'], [$setup->view()['has_key'], $setup->view()['state']], 'Nothing was saved.');

        self::assertSame(['connected' => false, 'key_changed' => true], $setup->connect(['key' => TelegramStandIn::KEY, 'username' => '@Your_Username'], new Actor('1', 'Ada')));
        $stored = (string) $this->state->get(TelegramState::BOT);
        self::assertStringNotContainsString(TelegramStandIn::KEY, $stored, 'It is stored encrypted.');
        self::assertSame(TelegramStandIn::KEY, $this->site->cipher->decrypt((string) json_decode($stored, true)['key']));
        self::assertSame('1', $this->connection->fetchOne("SELECT updated_by FROM cattophp_telegram_state WHERE name = 'bot'"));
        $view = $setup->view();
        self::assertStringNotContainsString(TelegramStandIn::KEY, (string) json_encode($view), 'It is never shown back.');
        self::assertSame(['waiting', true, false, TelegramStandIn::BOT, 'Your_Username', 'Not connected', 'neutral'], [$view['state'], $view['has_key'], $view['connected'], $view['bot_name'], $view['username'], $view['title'], $view['title_tone']]);
        self::assertSame(['connected' => false, 'key_changed' => false], $setup->connect(['key' => '', 'username' => 'Your_Username']), 'Leaving it empty keeps the key.');
        self::assertSame(TelegramStandIn::KEY, $this->bot->key());
        $this->refusedWith('That is the bot.', fn() => $setup->connect(['key' => '', 'username' => TelegramStandIn::BOT]));
    }

    public function testOnlyTheNamedAccountIsConnectedAndGetsTheTest(): void
    {
        $setup = $this->setupPage('demo');
        $this->telegram->writes(999, 'a_stranger', 'hello');
        foreach (['', 'no spaces here', 'abc', 'far_too_long_to_be_a_telegram_username'] as $wrong) {
            $this->refusedWith('Telegram username', fn() => $setup->connect(['key' => '', 'username' => $wrong]));
        }
        self::assertFalse($setup->connect(['key' => '', 'username' => '@your_username'])['connected'], 'A stranger who wrote to the bot connects nobody.');
        self::assertSame(['your_username', 'waiting'], [$setup->view()['username'], $setup->view()['state']]);
        $this->refusedWith('Connect to Telegram first.', fn() => $setup->sendTest());
        $this->telegram->writes((int) self::OWNER_CHAT, 'YOUR_USERNAME', 'hello');
        self::assertTrue($setup->connect(['key' => '', 'username' => 'your_username'])['connected'], 'A matching username connects, whatever its case.');
        self::assertSame([self::OWNER_CHAT, 'connected', 'Connected: @' . TelegramStandIn::BOT . ' to @your_username', 'success'], [$this->bot->saved()['chat_id'], $setup->view()['state'], $setup->view()['title'], $setup->view()['title_tone']]);
        $setup->sendTest();
        self::assertSame(['[DEMO] Test message: your messages from this site will arrive here (example.com)'], $this->telegram->textsTo(self::OWNER_CHAT), 'The named account, never the stranger.');
        $setup->connect(['key' => '', 'username' => 'another_account']);
        self::assertFalse($setup->view()['connected'], 'Another account has another chat, so nothing goes to the old one.');
    }

    public function testATestMessageIsLoggedAndNeverSentAgain(): void
    {
        $this->connectOwner();
        $setup = $this->setupPage();
        $setup->sendTest();
        $this->telegram->blocked[] = self::OWNER_CHAT;
        try {
            $setup->sendTest();
            self::fail('Telegram refused it.');
        } catch (TelegramFailed $refused) {
            self::assertSame(403, $refused->getCode());
        }
        self::assertSame([2, 1], [(int) $this->connection->fetchOne("SELECT COUNT(*) FROM cattophp_telegram_messages WHERE kind = 'test'"), (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_telegram_messages WHERE sent_at IS NULL')]);
        self::assertSame([], $this->log->waiting(30), 'A failed test goes at once or not at all: the sender never sends it.');
        self::assertSame([0, null, 'connected'], [$setup->view()['waiting'], $setup->view()['last_error'], $setup->view()['state']], 'And the page does not say the connection is failing.');
    }

    public function testTheChoicesSaveWithoutTouchingTheConnectionAndDisconnectingForgetsOnlyTheConnection(): void
    {
        $this->connectOwner();
        $setup = $this->setupPage();
        self::assertSame(['on_register' => true, 'on_sale' => true], $setup->view()['choices'], 'On until turned off.');
        $setup->saveChoices(['on_register' => true, 'on_sale' => false, 'key' => true, 'unknown' => false], false, new Actor('1', 'Ada'));
        self::assertSame([true, false, true, false], [$setup->view()['on_register'], $setup->view()['on_sale'], $setup->view()['connected'], $setup->view()['sign_in']]);
        self::assertSame(TelegramStandIn::KEY, $this->bot->key(), 'A choice named like the key changes nothing.');
        self::assertFalse($this->bot->choice('unknown', false), 'A choice the page does not offer is not kept.');
        $setup->dropConnection(new Actor('1', 'Ada'));
        self::assertSame([true, false, false, true, false, '', ''], [$setup->view()['on_register'], $setup->view()['on_sale'], $setup->view()['connected'], $setup->view()['connection_dropped'], $setup->view()['has_key'], $setup->view()['username'], $this->bot->key()]);
    }

    public function testAPersonConnectsTheirTelegramByTheBotsStart(): void
    {
        $this->connections->start('41', 'thandi@example.com', 'a-place');
        $view = $this->connections->view('41');
        self::assertSame('waiting', $view['state']);
        self::assertStringStartsWith('https://t.me/' . TelegramStandIn::BOT . '?start=', $view['link']);
        $this->telegram->writes(777001, 'Thandi_M', '/start ' . $this->tokenOf($view['link']));
        self::assertSame(1, $this->inbox->read());
        self::assertSame(['on', 'Thandi_M'], [$this->connections->view('41')['state'], $this->connections->view('41')['username']]);
        self::assertSame(['Your Telegram account is now connected to example.com'], $this->telegram->textsTo('777001'));
        self::assertSame(['account_id' => '41', 'username' => 'Thandi_M'], $this->site->activity->recent(1, 'telegram.connected')[0]->details);
        self::assertSame(['connected', '41'], array_values($this->connection->fetchAssociative("SELECT kind, to_account FROM cattophp_telegram_messages WHERE chat_id = '777001'") ?: []));
        self::assertTrue($this->connections->test('41'));
        self::assertSame('Test message from example.com', $this->telegram->textsTo('777001')[1] ?? null);
        $this->refusedWith('connected already', fn() => $this->connections->start('41', 'thandi@example.com'));
        $this->connections->dropConnection('41');
        self::assertSame(['off', null], [$this->connections->view('41')['state'], $this->accounts->forAccount('41')]);
        self::assertCount(1, $this->site->activity->recent(5, 'telegram.connection_dropped'));
    }

    public function testAChatConnectsToOneAccountOnlyAndTheBotSaysSo(): void
    {
        $this->connections->start('41', 'thandi@example.com');
        $this->telegram->writes(777001, 'Thandi_M', '/start ' . $this->tokenOf($this->connections->view('41')['link']));
        $this->inbox->read();
        $_SESSION = [];
        $this->connections->start('42', 'sipho@example.com');
        $link = $this->connections->view('42')['link'];
        $this->telegram->writes(777001, 'Thandi_M', '/start ' . $this->tokenOf($link));
        $this->inbox->read();
        $view = $this->connections->view('42');
        self::assertSame(['off', TelegramConnections::TAKEN], [$view['state'], $view['note']['words'] ?? '']);
        self::assertStringContainsString('already connected to another account', $this->telegram->textsTo('777001')[1] ?? '');
        self::assertSame('41', $this->accounts->forChat('777001')['account'] ?? null, 'It stays the first person\'s.');
        self::assertSame('taken', $this->site->activity->recent(1, 'telegram.connect_refused')[0]->details['why'] ?? null);
        // A used link is answered, and connects nobody.
        $this->telegram->writes(777002, 'someone_else', '/start ' . $this->tokenOf($link));
        $this->inbox->read();
        self::assertStringContainsString('has run out or was used already', $this->telegram->textsTo('777002')[0] ?? '');
        self::assertNull($this->accounts->forChat('777002'));
        $this->bot->setSignIn(false);
        $this->refusedWith(TelegramConnections::NOT_OFFERED, fn() => $this->connections->start('43', 'lerato@example.com'));
    }

    public function testABareStartIsAnsweredAndUsernamesFollowTelegram(): void
    {
        $this->accounts->connect('41', '5551001', 'thandi_m');
        $this->accounts->connect('42', '5551002', null);
        $this->telegram->writes(888001, 'a_stranger', '/start');
        $this->telegram->writes(5551001, 'thandi_new', 'hello');
        $this->telegram->writes(5551002, 'thandi_m', 'hello');
        $this->telegram->writes(888002, 'another', 'what is this');
        self::assertSame(4, $this->inbox->read());
        self::assertStringStartsWith('This bot sends sign-in messages for example.com', $this->telegram->textsTo('888001')[0] ?? '');
        self::assertSame([], $this->telegram->textsTo('888002'), 'Anything else is left alone.');
        self::assertSame('thandi_new', $this->accounts->forAccount('41')['username'] ?? null, 'A new username.');
        self::assertSame('42', $this->accounts->forUsername('@Thandi_M')['account'] ?? null, 'A username belongs to whoever holds it now.');
        self::assertSame(0, $this->inbox->read(), 'Read once: what was read is confirmed.');
    }

    public function testAMessageFromBeforeTheLastTenMinutesIsReadButNeverAnswered(): void
    {
        $this->connections->start('41', 'thandi@example.com');
        $old = time() - TelegramInbox::ANSWERS_FOR - 60;
        $this->telegram->writes(888003, 'early_riser', '/start', $old);
        $this->telegram->writes(777003, 'Thandi_M', '/start ' . $this->tokenOf($this->connections->view('41')['link']), $old);
        self::assertSame(2, $this->inbox->read());
        self::assertSame([], $this->telegram->sent, 'Nothing was answered.');
        self::assertNull($this->accounts->forChat('777003'), 'And nothing connected.');
        self::assertSame('888003', $this->inbox->seenChat('@early_riser'), 'Still seen, for connecting the owner.');
    }

    public function testNothingIsReadWhileSignInByTelegramIsOffAndTheOwnersConnectFindsWhatWasRead(): void
    {
        $this->bot->setSignIn(false);
        $this->telegram->writes(888001, 'a_stranger', '/start');
        self::assertSame(0, $this->inbox->read());
        self::assertSame(0, $this->telegram->getUpdatesCalls, 'Telegram is not even asked.');
        self::assertFalse($this->bot->signInOffered());
        $this->bot->setSignIn(true);
        $this->telegram->writes((int) self::OWNER_CHAT, 'Your_Username', 'hello');
        self::assertSame(2, $this->inbox->read());
        self::assertSame(0, $this->inbox->read());
        self::assertSame([], $this->telegram->updates, 'Used up: Telegram no longer has them.');
        self::assertTrue($this->setupPage()->connect(['key' => '', 'username' => '@your_username'])['connected'], 'Found among the chats seen lately.');
        self::assertSame(self::OWNER_CHAT, $this->bot->saved()['chat_id']);
        $this->bot->forget();
        self::assertFalse($this->bot->signInOffered(), 'Turned on, with no key: not offered.');
    }

    public function testAPersonsMessageGoesAtOnceAndIsLoggedWithoutItsSecrets(): void
    {
        $this->accounts->connect('41', '5553001', 'lerato_n');
        $this->messenger->hold('sign_in', '5553001', '41', 'lerato_n', 'Your code is 123456 and your link https://example.com/s/abc', 'Sign-in code and link for example.com (not kept)', ['123456', 'abc']);
        self::assertSame([], $this->telegram->sent, 'Held until the answer has gone.');
        self::assertSame(1, $this->messenger->deliverHeld());
        self::assertSame('Your code is 123456 and your link https://example.com/s/abc', $this->telegram->message(0)['text']);
        $row = $this->connection->fetchAssociative("SELECT kind, body, to_account, to_username, last_error FROM cattophp_telegram_messages WHERE chat_id = '5553001'") ?: [];
        self::assertSame(['sign_in', 'Sign-in code and link for example.com (not kept)', '41', 'lerato_n', null], array_values($row), 'Logged by what it was, never by what it said.');
        $this->telegram->blocked[] = '5553001';
        self::assertFalse($this->messenger->send('test', '5553001', '41', 'lerato_n', 'Test'));
        self::assertSame(TelegramMessenger::BLOCKED, $this->connection->fetchOne("SELECT last_error FROM cattophp_telegram_messages WHERE kind = 'test'"));
        // The owner's sender leaves a person's messages alone, and its own carry the owner's username.
        $this->connectOwner();
        $this->log->record('order:check', 'sale', 'Sale: a check');
        self::assertStringStartsWith('Sent 1 message.', $this->sender()->run(), 'Only the owner\'s own, once.');
        self::assertSame([null, 'your_username'], array_values($this->connection->fetchAssociative("SELECT chat_id, to_username FROM cattophp_telegram_messages WHERE body = 'Sale: a check'") ?: []));
        self::assertSame(['waiting' => 0, 'last_error' => null], array_intersect_key($this->log->status(), ['waiting' => 1, 'last_error' => 1]), 'A person who blocked the bot never makes the owner\'s connection look broken.');
        $this->bot->forget();
        self::assertFalse($this->messenger->send('test', '5553001', '41', 'lerato_n', 'Test'));
        self::assertSame('No bot key is saved.', $this->connection->fetchOne("SELECT last_error FROM cattophp_telegram_messages WHERE kind = 'test' ORDER BY id DESC LIMIT 1"));
    }

    /** cattolms's records, moved in as they stand by its upgrade migration, are read as the module writes its own. */
    public function testAnApplicationsOwnRecordsAreReadAsItWroteThem(): void
    {
        $encrypted = $this->site->cipher->encrypt(TelegramStandIn::KEY);
        $this->state->set(TelegramState::BOT, (string) json_encode(['key' => $encrypted, 'bot_name' => 'site_alerts_bot', 'username' => 'your_username', 'chat_id' => self::OWNER_CHAT,
            'on_register' => true, 'on_order' => false, 'on_sale' => true, 'sign_in' => true, 'connection_dropped' => false]), '1');
        $this->connection->executeStatement("INSERT INTO cattophp_telegram_accounts (account_id, chat_id, username) VALUES ('12', '5551001', 'thandi_m')");
        $this->connection->executeStatement("INSERT INTO cattophp_telegram_messages (id, reference, kind, body, to_account) VALUES (310, 'money_report:2026-10', 'money_report', 'October: R12,500 in 4 orders', NULL)");
        $this->connection->executeStatement("SELECT setval(pg_get_serial_sequence('cattophp_telegram_messages', 'id'), 310)");
        self::assertSame([true, true, false, true], [$this->bot->connected(), $this->bot->choice('on_register'), $this->bot->choice('on_order'), $this->bot->signInOffered()]);
        self::assertSame('12', $this->accounts->forUsername('thandi_m')['account'] ?? null);
        self::assertSame('Sent 1 message.', $this->sender()->run());
        self::assertSame(['October: R12,500 in 4 orders'], $this->telegram->textsTo(self::OWNER_CHAT));
        $this->log->record('order:1', 'sale', 'Next');
        self::assertSame(311, (int) $this->connection->fetchOne('SELECT MAX(id) FROM cattophp_telegram_messages'), 'And carried on from them.');
    }

    private function connectOwner(): void
    {
        $this->bot->setUsername('your_username');
        $this->bot->setChat(self::OWNER_CHAT);
    }

    private function words(string $mode = 'live'): TelegramWords
    {
        return new TelegramWords(new SiteMode(null, $mode), $this->site->installation);
    }

    private function owner(string $mode = 'live'): OwnerMessages
    {
        return new OwnerMessages($this->bot, $this->log, $this->words($mode));
    }

    private function sender(): OwnerMessageSender
    {
        return new OwnerMessageSender($this->log, $this->bot, new TelegramClient($this->telegram->client()), $this->clock, $this->inbox);
    }

    private function setupPage(string $mode = 'live'): TelegramSetup
    {
        return new TelegramSetup($this->bot, $this->log, $this->words($mode), new TelegramClient($this->telegram->client()), $this->inbox, ['on_register', 'on_sale']);
    }

    /** @return list<string> the owner's messages kept, in order */
    private function bodies(): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn('SELECT body FROM cattophp_telegram_messages WHERE chat_id IS NULL ORDER BY id'));
    }

    private function tokenOf(string $link): string
    {
        return rawurldecode(substr($link, (int) strpos($link, '=') + 1));
    }

    private function refusedWith(string $words, callable $attempt): void
    {
        try {
            $attempt();
            self::fail('Not refused: ' . $words);
        } catch (InvalidArgumentException $refused) {
            self::assertStringContainsString($words, $refused->getMessage());
        }
    }
}
