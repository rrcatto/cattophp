<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Access\AccessRefused;
use CattoPHP\Access\DbalRoleStore;
use CattoPHP\Access\Roles;
use CattoPHP\Account\AccountRecords;
use CattoPHP\Account\ForgetsAccounts;
use CattoPHP\Activity\Actor;
use CattoPHP\Activity\DbalActivityStore;
use CattoPHP\Links\DbalLinkStore;
use CattoPHP\Security\TwoStep\DbalTwoStepStore;
use CattoPHP\Sessions\DbalSessionStore;
use CattoPHP\Settings\DbalSettingsStore;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Tools\DsnParser;
use RuntimeException;

/**
 * Forgetting an account in a project with every module that keeps records of accounts switched on: what is the
 * account's alone goes, what stays no longer names it, another account's records are untouched, and the last
 * administrator is refused with nothing changed.
 */
final class AccountRecordsTest extends DatabaseTestCase
{
    /** Anna, the account being deleted (TestKernel's ExampleAccounts). */
    private const ANNA = '2';

    /** Olive, the owner, whose records stay as they are. */
    private const OLIVE = '1';

    public function testEveryModuleForgetsTheAccountAndLeavesAnotherAccountsRecordsAlone(): void
    {
        $folder = Foundations::folder();
        $kernel = new TestKernel($folder, (string) getenv('CATTOPHP_TEST_DATABASE_URL'), scheduler: true, setup: true, protection: true, telegram: true,
            search: true, api: true, signup: true, identity: true, inbox: true, mailing: true, accounts: true, signIns: true);
        try {
            $kernel->boot();
            $records = $kernel->getContainer()->get(AccountRecords::class);
            self::assertInstanceOf(AccountRecords::class, $records);
            $this->recordsOf(self::ANNA, self::OLIVE);
            $this->recordsOf(self::OLIVE, self::ANNA);
            $this->namedOnlyInWhatTheySay();
            // Olive is the installation's administrator (owner@example.com) and the project flags her one: with Anna's role
            // taken away, Olive is the last administrator, by her role, her flag and her address alike.
            $this->connection->executeStatement("DELETE FROM cattophp_account_roles WHERE account_id = '" . self::ANNA . "'");
            $unrefused = $this->counts();

            try {
                $records->forget(self::OLIVE, new Actor(self::ANNA, 'Anna Example'));
                self::fail('The last administrator was forgotten.');
            } catch (AccessRefused $refused) {
                self::assertSame('This is the last administrator: give the role to someone else first.', $refused->getMessage());
            }
            self::assertSame($unrefused, $this->counts(), 'Refused: nothing changed.');

            $this->connection->executeStatement("INSERT INTO cattophp_account_roles (account_id, role_key, granted_at, granted_by) VALUES ('" . self::ANNA . "', 'ADMIN', now(), '" . self::OLIVE . "')");
            $before = $this->counts();
            $done = $records->forget(self::ANNA, new Actor(self::OLIVE, 'Olive Owner'));

            self::assertSame(self::keyed(['roles' => 1, 'two-step keys' => 1, 'links' => 2, 'sign-ins' => 2, 'Telegram connections' => 1, 'API tokens' => 1,
                'sign-up attempts' => 1, 'identity checks' => 1, 'identity retries allowed' => 1, 'mailing list answers' => 1, 'forwarded emails' => 0]), self::keyed($done['removed']));
            self::assertSame(self::keyed(['settings' => 1, 'setup marks' => 1, 'Site Log requests' => 1, 'flags' => 1, 'lockouts' => 2, 'refused addresses' => 1,
                'Telegram messages' => 3, 'Telegram bot values' => 1, 'API tokens made for others' => 1, 'revoked identity checks' => 1,
                'Inbox messages sent' => 1, 'Inbox messages answered' => 1, 'Inbox replies and drafts' => 1, 'spam filter lessons' => 1, 'blocked senders' => 1,
                'catto-mail events' => 1, 'mailing copies' => 1, 'mailing lists' => 1, 'campaigns' => 2, 'addresses never emailed' => 1, 'search pages' => 1,
                'activity entries' => 6]), self::keyed($done['unnamed']));

            // Nothing names Anna now, anywhere CattoPHP keeps an account.
            foreach (self::columns() as [$table, $column]) {
                self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM $table WHERE $column = :anna", ['anna' => self::ANNA]), "$table.$column names nobody forgotten");
            }
            self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM cattophp_links WHERE email = 'anna@example.com'"), 'Her joining link went with her.');
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM cattophp_links WHERE email = 'someone@example.org'"), 'Another address\'s joining link stays.');
            self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM cattophp_telegram_messages WHERE to_username = 'anna_example'"), 'Her Telegram username is not kept.');
            // Review finding 7: what stays in the modules' records names her no more.
            self::assertSame([AccountRecords::FORGOTTEN_EMAIL, '{}', 0], [$this->connection->fetchOne("SELECT email FROM cattophp_mailing_copies WHERE email NOT LIKE 'owner%'"),
                $this->connection->fetchOne("SELECT content FROM cattophp_mailing_copies WHERE email NOT LIKE 'owner%'"),
                (int) $this->connection->fetchOne("SELECT COUNT(*) FROM cattophp_mailing_copies WHERE token = repeat('2', 32)")], 'Her copy: the forgotten address, nothing of her own, and links that no longer open it.');
            self::assertSame(['sender_name' => '', 'sender_email' => AccountRecords::FORGOTTEN_EMAIL, 'account_reference' => null, 'sender_ip' => null, 'sender_agent' => null],
                $this->connection->fetchAssociative("SELECT sender_name, sender_email, account_reference, sender_ip, sender_agent FROM cattophp_inbox_conversations WHERE reference = 'MSG-000002'"), 'Her message to the site, from nobody now.');
            self::assertSame(['', AccountRecords::FORGOTTEN_EMAIL], $this->connection->fetchNumeric("SELECT from_name, from_email FROM cattophp_inbox_items i JOIN cattophp_inbox_conversations c ON c.id = i.conversation_id WHERE c.reference = 'MSG-000002' AND direction = 'received'"));
            self::assertSame([[null, null, ''], [null, null, ''], [null, null, null]], $this->connection->fetchAllNumeric("SELECT to_account, to_username, chat_id FROM cattophp_telegram_messages WHERE reference IN ('message-2', 'message-to-her-chat', 'message-to-the-owner') ORDER BY id"),
                'To her account, her chat or her username: to nobody; a person\'s chat empty, the owner\'s own still the owner\'s.');
            self::assertSame([AccountRecords::FORGOTTEN_EMAIL, 'Delivered to ' . AccountRecords::FORGOTTEN_EMAIL . '.'], [json_decode((string) $this->connection->fetchOne("SELECT payload FROM cattophp_cattomail_events WHERE event_id = 'evt-anna'"), true)['data']['message']['recipient'] ?? null,
                $this->connection->fetchOne("SELECT outcome FROM cattophp_cattomail_events WHERE event_id = 'evt-anna'")], 'catto-mail\'s event gives her address no more, whatever its case.');
            self::assertStringContainsString('hanna@example.com', (string) $this->connection->fetchOne("SELECT payload FROM cattophp_cattomail_events WHERE event_id = 'evt-hanna'"), 'Another address that holds hers is someone else\'s.');
            // Review finding 6: entries that named her only in what they say, by someone else or by the system.
            self::assertSame(self::sortedKeys(['account' => AccountRecords::FORGOTTEN, 'session_id' => AccountRecords::FORGOTTEN, 'reason' => 'The photo of A deleted account was blurred.']),
                $this->detailsOf('identity.verification_declined'), 'Her id, her identity check\'s session and her name, by the system.');
            self::assertSame(self::sortedKeys(['account_id' => AccountRecords::FORGOTTEN, 'username' => AccountRecords::FORGOTTEN, 'note' => 'Connected as ' . AccountRecords::FORGOTTEN . '.']),
                $this->detailsOf('telegram.connected'), 'Her id and her Telegram username, with or without its @.');
            self::assertSame(self::sortedKeys(['old' => 'owner@example.com, ' . AccountRecords::FORGOTTEN . ', hanna@example.com', 'new' => 'owner@example.com', 'setting' => 'AUTH_SIGNIN_ALLOWED_EMAILS']),
                $this->detailsOf('settings.changed'), 'Her address inside a list Olive saved.');
            self::assertSame([null], array_values(array_unique($this->connection->fetchFirstColumn("SELECT ip_address FROM cattophp_activity WHERE action IN ('identity.verification_declined', 'telegram.connected', 'settings.changed')"))), 'No network address where it named her.');
            self::assertSame(self::sortedKeys(['count' => 2, 'account_id' => '1']), $this->detailsOf('example.olive_only'), 'Olive\'s, and a number that is no account, as they were.');
            self::assertSame('203.0.113.9', $this->connection->fetchOne("SELECT ip_address FROM cattophp_activity WHERE action = 'example.olive_only'"));
            // Anywhere at all: her address, her name and her Telegram username are in no record CattoPHP keeps.
            foreach ($this->connection->fetchFirstColumn("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name") as $table) {
                $named = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table . " t WHERE CAST(t AS text) ~* '(^|[^a-z0-9._%+@-])(anna@example\\.com|anna_example|anna example)([^a-z0-9_@]|$)'");
                self::assertSame(0, $named, $table . ' names her nowhere');
            }

            // What stays says what happened, by nobody named.
            self::assertSame(['email' => AccountRecords::FORGOTTEN, 'browser' => 'Firefox'], json_decode((string) $this->connection->fetchOne("SELECT details FROM cattophp_activity WHERE action = 'sign_in.signed_in' ORDER BY id LIMIT 1"), true), 'Her address in what it says, whatever its case; the rest as it was.');
            self::assertSame(['email' => 'OWNER@EXAMPLE.COM', 'browser' => 'Firefox'], json_decode((string) $this->connection->fetchOne("SELECT details FROM cattophp_activity WHERE action = 'sign_in.signed_in' AND actor_id = '1'"), true), 'Olive\'s, as it was.');
            $entries = $this->connection->fetchAllAssociative('SELECT action, actor_id, actor_name, subject_type, subject_id FROM cattophp_activity ORDER BY id');
            self::assertSame(['action' => 'sign_in.signed_in', 'actor_id' => null, 'actor_name' => AccountRecords::FORGOTTEN, 'subject_type' => 'account', 'subject_id' => null], $entries[0]);
            self::assertSame(['action' => 'access.role_given', 'actor_id' => self::OLIVE, 'actor_name' => 'Olive Owner', 'subject_type' => 'account', 'subject_id' => null], $entries[1], 'Olive gave Anna a role: still Olive\'s doing, about nobody now.');
            $last = $this->connection->fetchAssociative("SELECT actor_id, subject_type, subject_id, details FROM cattophp_activity WHERE action = 'account.forgotten'");
            self::assertIsArray($last, 'The forgetting is in the log.');
            self::assertSame([self::OLIVE, null, null], [$last['actor_id'], $last['subject_type'], $last['subject_id']], 'By whoever did it, about nobody.');
            self::assertStringNotContainsString('anna', strtolower((string) $last['details']));

            // Olive's own records are as they were, but for those of Anna's that named Olive too and went with her (the role
            // Olive gave her, the token Olive made her and the identity check of hers Olive revoked), and the forgetting,
            // which Olive did.
            $after = $this->counts();
            $changed = ['cattophp_account_roles.granted_by' => -1, 'cattophp_api_tokens.created_by' => -1, 'cattophp_identity_verifications.revoked_by' => -1, 'cattophp_activity.actor_id' => 1];
            foreach (self::columns() as [$table, $column]) {
                self::assertSame($before[$table . '.' . self::OLIVE . '.' . $column] + ($changed[$table . '.' . $column] ?? 0), $after[$table . '.' . self::OLIVE . '.' . $column], "Olive's $table.$column");
            }
            self::assertNull($this->connection->fetchOne("SELECT granted_by FROM cattophp_account_roles WHERE account_id = '" . self::OLIVE . "'"));
            self::assertSame('Olive Owner', $this->connection->fetchOne("SELECT actor_name FROM cattophp_activity WHERE actor_id = '" . self::OLIVE . "' LIMIT 1"));
        } finally {
            $kernel->shutdown();
            Foundations::remove($folder);
            restore_exception_handler();
        }
    }

    public function testItIsAllOrNothing(): void
    {
        $this->connection->executeStatement("INSERT INTO cattophp_account_roles (account_id, role_key, granted_at) VALUES ('5', 'ADMIN', now()), ('" . self::ANNA . "', 'ADMIN', now())");
        $this->connection->executeStatement("INSERT INTO cattophp_sessions (token_hash, public_id, account_id, started_at, last_seen_at, expires_at) VALUES ('h-anna', 'p-anna', '" . self::ANNA . "', now(), now(), now() + interval '1 day')");
        $site = new Foundations();
        $failing = new class implements ForgetsAccounts {
            public function forgetAccount(string $account, ?string $email): array
            {
                throw new RuntimeException('A project\'s records could not be forgotten.');
            }
        };
        $records = new AccountRecords(new Roles(new DbalRoleStore($this->connection), $site->roles->catalogue(), $site->activity, $site->clock), new DbalRoleStore($this->connection),
            new DbalTwoStepStore($this->connection, $site->cipher, $site->clock), new DbalLinkStore($this->connection), new DbalSessionStore($this->connection), new DbalSettingsStore($this->connection),
            new DbalActivityStore($this->connection), $site->activity, [$failing], $this->connection);

        try {
            $records->forget(self::ANNA);
            self::fail('A failure went unnoticed.');
        } catch (RuntimeException $failed) {
            self::assertSame('A project\'s records could not be forgotten.', $failed->getMessage());
        }

        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM cattophp_account_roles WHERE account_id = '" . self::ANNA . "'"), 'Her role is still hers.');
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM cattophp_sessions WHERE account_id = '" . self::ANNA . "'"), 'Her sign-in is still kept.');
    }

    /**
     * Review finding 3: two administrators forgetting each other at once leave one. The first, inside the project's own
     * deleting transaction (which it joins), holds the Administrator role's holders locked until that transaction ends, so
     * the second waits for it (here until its lock timeout) instead of counting two; once the first has committed, the
     * second counts one and is refused. Taking the role away is one locked step too.
     */
    public function testTwoAdministratorsForgettingOrTakingTheRoleFromEachOtherAtOnceLeaveOne(): void
    {
        $this->connection->executeStatement("INSERT INTO cattophp_account_roles (account_id, role_key, granted_at) VALUES ('1', 'ADMIN', now()), ('2', 'ADMIN', now())");
        $other = DriverManager::getConnection((new DsnParser())->parse((string) getenv('CATTOPHP_TEST_DATABASE_URL')));
        try {
            $other->executeStatement("SET lock_timeout = '300ms'");
            $this->connection->beginTransaction();
            $this->records($this->connection)->forget(self::ANNA, new Actor(self::OLIVE, 'Olive Owner'));
            self::assertTrue($this->connection->isTransactionActive(), 'It joined the transaction already open.');
            try {
                $this->records($other)->forget(self::OLIVE, new Actor(self::ANNA, 'Anna Example'));
                self::fail('The second forgetting counted two administrators while the first was under way.');
            } catch (DriverException $refused) {
                self::assertTrue(self::waitedForALock($refused), 'It waited for the first: ' . $refused->getMessage());
            }
            $this->connection->commit();
            try {
                $this->records($other)->forget(self::OLIVE, new Actor(self::ANNA, 'Anna Example'));
                self::fail('The last administrator was forgotten.');
            } catch (AccessRefused $refused) {
                self::assertSame('This is the last administrator: give the role to someone else first.', $refused->getMessage());
            }
            self::assertSame([self::OLIVE], $this->connection->fetchFirstColumn("SELECT account_id FROM cattophp_account_roles WHERE role_key = 'ADMIN'"));

            // Taking the role away: the same.
            $this->connection->executeStatement("INSERT INTO cattophp_account_roles (account_id, role_key, granted_at) VALUES ('2', 'ADMIN', now())");
            $site = new Foundations();
            $roles = static fn (Connection $connection): Roles => new Roles(new DbalRoleStore($connection), $site->roles->catalogue(), $site->activity, $site->clock);
            $theirs = $roles($other);
            $this->connection->beginTransaction();
            $roles($this->connection)->revoke(self::ANNA, Roles::ADMIN, new Actor(self::OLIVE, 'Olive Owner'));
            try {
                $theirs->revoke(self::OLIVE, Roles::ADMIN, new Actor(self::ANNA, 'Anna Example'));
                self::fail('The role was taken from the other administrator while the first was under way.');
            } catch (DriverException $refused) {
                self::assertTrue(self::waitedForALock($refused), 'It waited for the first: ' . $refused->getMessage());
            }
            $this->connection->commit();
            try {
                $theirs->revoke(self::OLIVE, Roles::ADMIN, new Actor(self::ANNA, 'Anna Example'));
                self::fail('The last administrator lost the role.');
            } catch (AccessRefused $refused) {
                self::assertSame('This is the last administrator: give the role to someone else first.', $refused->getMessage());
            }
            self::assertSame([self::OLIVE], $this->connection->fetchFirstColumn("SELECT account_id FROM cattophp_account_roles WHERE role_key = 'ADMIN'"));
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $other->close();
        }
    }

    /** @return array<mixed> an entry's details, its keys in order (the database keeps them in its own) */
    private function detailsOf(string $action): array
    {
        $details = json_decode((string) $this->connection->fetchOne('SELECT details FROM cattophp_activity WHERE action = :action', ['action' => $action]), true);

        return self::sortedKeys(is_array($details) ? $details : []);
    }

    /**
     * @param array<mixed> $details
     * @return array<mixed>
     */
    private static function sortedKeys(array $details): array
    {
        ksort($details);

        return $details;
    }

    /** Whether a database refusal was a lock waited for until its timeout (SQLSTATE 55P03). */
    private static function waitedForALock(DriverException $refused): bool
    {
        return $refused->getSQLState() === '55P03';
    }

    private function records(Connection $connection): AccountRecords
    {
        $site = new Foundations();

        return new AccountRecords(new Roles(new DbalRoleStore($connection), $site->roles->catalogue(), $site->activity, $site->clock), new DbalRoleStore($connection),
            new DbalTwoStepStore($connection, $site->cipher, $site->clock), new DbalLinkStore($connection), new DbalSessionStore($connection), new DbalSettingsStore($connection),
            new DbalActivityStore($connection), $site->activity, [], $connection);
    }

    /** Every record CattoPHP keeps that names an account, with $other in the records that name two. */
    private function recordsOf(string $account, string $other): void
    {
        $db = $this->connection;
        $a = ['account' => $account, 'other' => $other, 'email' => $account === self::ANNA ? 'anna@example.com' : 'owner@example.com', 'n' => $account];
        // The foundations.
        $db->executeStatement("INSERT INTO cattophp_account_roles (account_id, role_key, granted_at, granted_by) VALUES (:account, 'ADMIN', now(), :other)", $a);
        $db->executeStatement("INSERT INTO cattophp_two_step (account_id, secret_encrypted, last_counter, set_up_at, updated_at) VALUES (:account, 'kept-encrypted', 0, now(), now())", $a);
        $db->executeStatement("INSERT INTO cattophp_links (token_hash, purpose, account_id, created_at, expires_at) VALUES ('link-' || :n, 'sign_in', :account, now(), now() + interval '30 minutes')", $a);
        $db->executeStatement("INSERT INTO cattophp_sessions (token_hash, public_id, account_id, email, started_at, last_seen_at, expires_at)
            SELECT 'session-' || :n || '-' || n, 'public-' || :n || '-' || n, :account, :email, now(), now(), now() + interval '1 day' FROM generate_series(1, CAST(:sessions AS int)) n", $a + ['sessions' => $account === self::ANNA ? 2 : 1]);
        $db->executeStatement("INSERT INTO cattophp_settings (name, value, updated_at, updated_by) VALUES ('SETTING_' || :n, 'yes', now(), :account)", $a);
        $db->executeStatement("INSERT INTO cattophp_activity (happened_at, action, actor_id, actor_name, subject_type, subject_id, details) VALUES (now(), 'sign_in.signed_in', :account, :email, 'account', :account, jsonb_build_object('email', upper(:email), 'browser', 'Firefox'))", $a);
        $db->executeStatement("INSERT INTO cattophp_activity (happened_at, action, actor_id, actor_name, subject_type, subject_id) VALUES (now(), 'access.role_given', :other, CASE WHEN :other = '1' THEN 'Olive Owner' ELSE 'Anna Example' END, 'account', :account)", $a);
        // The setup checklist and site protection.
        $db->executeStatement("INSERT INTO cattophp_setup_steps (step_key, state, marked_at, marked_by) VALUES ('step-' || :n, 'done', now(), :account)", $a);
        $db->executeStatement("INSERT INTO cattophp_site_requests (address, subject, account_id, visitor, agent_kind, method, path, status) VALUES (CAST('203.0.113.' || :n AS inet), CAST('203.0.113.' || :n AS inet), :account, 'signed_in', 'browser', 'GET', '/', 200)", $a);
        $db->executeStatement("INSERT INTO cattophp_site_flags (subject, level, decided_by) VALUES (CAST('198.51.100.' || :n AS inet), 'medium', :account)", $a);
        $db->executeStatement("INSERT INTO cattophp_site_lockouts (kind, network, label, which, why, created_by, lifted_by) VALUES ('address', CAST('192.0.2.' || :n AS cidr), 'An address', 'lockout', 'By hand', :account, :other)", $a);
        $db->executeStatement("INSERT INTO cattophp_site_refused_emails (value, created_by) VALUES ('refused' || :n || '@example.net', :account)", $a);
        // Telegram.
        $db->executeStatement("INSERT INTO cattophp_telegram_accounts (account_id, chat_id, username) VALUES (:account, '10' || :n, CASE WHEN :account = '2' THEN 'anna_example' ELSE 'olive_owner' END)", $a);
        $db->executeStatement("INSERT INTO cattophp_telegram_messages (reference, kind, body, to_account, to_username, chat_id) VALUES ('message-' || :n, 'sign_in', 'Hello', :account, CASE WHEN :account = '2' THEN 'anna_example' ELSE 'olive_owner' END, '10' || :n)", $a);
        $db->executeStatement("INSERT INTO cattophp_telegram_state (name, value, updated_by) VALUES (CASE WHEN :account = '2' THEN 'bot' ELSE 'inbox' END, 'kept', :account)", $a);
        // The API, the sign-up check and identity verification.
        $db->executeStatement("INSERT INTO cattophp_api_tokens (public_id, account_id, name, token_hash, created_by) VALUES (gen_random_uuid(), :account, 'Own', repeat(:n, 64), :other)", $a);
        $db->executeStatement("INSERT INTO cattophp_signup_attempts (email, score, band, outcome, account) VALUES (:email, 10, 'low', 'completed', :account)", $a);
        $db->executeStatement("INSERT INTO cattophp_identity_verifications (account, session_id, revoked_by) VALUES (:account, 'session-' || :n, :other)", $a);
        $db->executeStatement("INSERT INTO cattophp_identity_retries (account) VALUES (:account)", $a);
        // The Inbox.
        $conversation = $db->fetchOne("INSERT INTO cattophp_inbox_conversations (reference, reason, sender_name, sender_email, account, account_reference, message, answered_by, sender_ip, sender_agent)
            VALUES ('MSG-00000' || :n, 'other', 'A person', :email, :account, 'REF-' || :n, 'Hello', :other, '203.0.113.' || :n, 'Firefox') RETURNING id", $a);
        $db->executeStatement("INSERT INTO cattophp_inbox_items (conversation_id, direction, kind, status, written_by) VALUES (:conversation, 'sent', 'reply', 'draft', :account)", $a + ['conversation' => $conversation]);
        $db->executeStatement("INSERT INTO cattophp_inbox_items (conversation_id, direction, kind, status, from_name, from_email, to_email) VALUES (:conversation, 'received', 'message', 'received', 'A person', :email, 'site@example.com')", $a + ['conversation' => $conversation]);
        $db->executeStatement("INSERT INTO cattophp_inbox_spam_training (conversation_id, verdict, tokens, trained_by) VALUES (:conversation, 'ham', '[]', :account)", $a + ['conversation' => $conversation]);
        $db->executeStatement("INSERT INTO cattophp_inbox_blocked_senders (value, kind, created_by) VALUES ('blocked' || :n || '@example.net', 'email', :account)", $a);
        // Mailing.
        $list = $db->fetchOne("INSERT INTO cattophp_mailing_lists (kind, subject, created_by) VALUES ('news', :n, :account) RETURNING id", $a);
        $db->executeStatement("INSERT INTO cattophp_mailing_subscriptions (list_id, account, status, source) VALUES (:list, :account, 'subscribed', 'test')", $a + ['list' => $list]);
        $db->executeStatement("INSERT INTO cattophp_mailing_subscription_events (list_id, account, status, source) VALUES (:list, :account, 'subscribed', 'test')", $a + ['list' => $list]);
        $db->executeStatement("INSERT INTO cattophp_mailing_suppressions (email, reason, created_by) VALUES ('bounced' || :n || '@example.net', 'manual', :account)", $a);
        $campaign = $db->fetchOne("INSERT INTO cattophp_mailing_campaigns (kind, name, created_by, approved_by) VALUES ('news', 'Campaign ' || :n, :account, :other) RETURNING id", $a);
        $db->executeStatement("INSERT INTO cattophp_mailing_copies (campaign_id, account, email, token) VALUES (:campaign, :account, :email, repeat(:n, 32))", $a + ['campaign' => $campaign]);
        // Search engines.
        $db->executeStatement("INSERT INTO cattophp_search_pages (page_key, title, updated_by) VALUES ('page-' || :n, 'A page', :account)", $a);
        $db->executeStatement("INSERT INTO cattophp_links (token_hash, purpose, email, created_at, expires_at) VALUES ('joining-' || :n, 'sign_in_join', :email, now(), now() + interval '30 minutes')", $a);
        if ($account === self::ANNA) {
            $db->executeStatement("INSERT INTO cattophp_links (token_hash, purpose, email, created_at, expires_at) VALUES ('joining-other', 'sign_in_join', 'someone@example.org', now(), now() + interval '30 minutes')");
        }
    }

    /**
     * What names Anna only in what it says: entries by the system and by Olive whose details hold her id, her identity
     * check's session, her Telegram username and her address in a list, each with a network address; messages to her
     * chat and to her username that no account of hers is named in; catto-mail's events, one for her address and one
     * for an address that holds hers; and an entry of Olive's that a number in it must not be taken for Anna's.
     */
    private function namedOnlyInWhatTheySay(): void
    {
        $db = $this->connection;
        $db->executeStatement("INSERT INTO cattophp_activity (happened_at, action, actor_id, actor_name, details, ip_address) VALUES
            (now(), 'identity.verification_declined', NULL, 'The system', jsonb_build_object('account', 2, 'session_id', 'session-2', 'reason', 'The photo of Anna Example was blurred.'), '203.0.113.9'),
            (now(), 'telegram.connected', '1', 'Olive Owner', jsonb_build_object('account_id', '2', 'username', 'anna_example', 'note', 'Connected as @anna_example.'), '203.0.113.9'),
            (now(), 'settings.changed', '1', 'Olive Owner', jsonb_build_object('setting', 'AUTH_SIGNIN_ALLOWED_EMAILS', 'old', 'owner@example.com, Anna@Example.com, hanna@example.com', 'new', 'owner@example.com'), '203.0.113.9'),
            (now(), 'example.olive_only', '1', 'Olive Owner', jsonb_build_object('count', 2, 'account_id', '1'), '203.0.113.9')");
        $db->executeStatement("INSERT INTO cattophp_telegram_messages (reference, kind, body, sent_at, to_account, to_username, chat_id) VALUES
            ('message-to-her-chat', 'answer', 'Hello', now(), NULL, 'anna_example', '102'),
            ('message-to-the-owner', 'order', 'An order', now(), NULL, 'Anna_Example', NULL)");
        $db->executeStatement("INSERT INTO cattophp_cattomail_events (event_id, event_type, payload, outcome) VALUES
            ('evt-anna', 'message.delivered', '{\"data\": {\"message\": {\"recipient\": \"Anna@Example.com\"}}}', 'Delivered to anna@example.com.'),
            ('evt-hanna', 'message.delivered', '{\"data\": {\"message\": {\"recipient\": \"hanna@example.com\"}}}', 'Delivered.')");
    }

    /** @return list<array{0:string,1:string}> every table and column of CattoPHP's that names an account */
    private static function columns(): array
    {
        return [
            ['cattophp_account_roles', 'account_id'], ['cattophp_account_roles', 'granted_by'], ['cattophp_two_step', 'account_id'], ['cattophp_links', 'account_id'],
            ['cattophp_sessions', 'account_id'], ['cattophp_settings', 'updated_by'], ['cattophp_activity', 'actor_id'], ['cattophp_activity', 'subject_id'],
            ['cattophp_setup_steps', 'marked_by'], ['cattophp_site_requests', 'account_id'], ['cattophp_site_flags', 'decided_by'], ['cattophp_site_lockouts', 'created_by'],
            ['cattophp_site_lockouts', 'lifted_by'], ['cattophp_site_refused_emails', 'created_by'], ['cattophp_telegram_accounts', 'account_id'],
            ['cattophp_telegram_messages', 'to_account'], ['cattophp_telegram_state', 'updated_by'], ['cattophp_api_tokens', 'account_id'], ['cattophp_api_tokens', 'created_by'],
            ['cattophp_signup_attempts', 'account'], ['cattophp_identity_verifications', 'account'], ['cattophp_identity_verifications', 'revoked_by'],
            ['cattophp_identity_retries', 'account'], ['cattophp_inbox_conversations', 'account'], ['cattophp_inbox_conversations', 'answered_by'],
            ['cattophp_inbox_items', 'written_by'], ['cattophp_inbox_spam_training', 'trained_by'], ['cattophp_inbox_blocked_senders', 'created_by'],
            ['cattophp_mailing_lists', 'created_by'], ['cattophp_mailing_subscriptions', 'account'], ['cattophp_mailing_subscription_events', 'account'],
            ['cattophp_mailing_suppressions', 'created_by'], ['cattophp_mailing_campaigns', 'created_by'], ['cattophp_mailing_campaigns', 'approved_by'],
            ['cattophp_mailing_copies', 'account'], ['cattophp_mailing_forwards', 'account'], ['cattophp_search_pages', 'updated_by'],
        ];
    }

    /** @return array<string,int> how many rows of each table name each account in each column */
    private function counts(): array
    {
        $counts = [];
        foreach (self::columns() as [$table, $column]) {
            foreach ([self::ANNA, self::OLIVE] as $account) {
                $counts[$table . '.' . $account . '.' . $column] = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM $table WHERE $column = :account", ['account' => $account]);
            }
        }
        $counts['links by address'] = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cattophp_links WHERE account_id IS NULL');

        return $counts;
    }

    /**
     * @param array<string,int> $counts
     * @return array<string,int> in the order of their names, which a module's place among the others does not decide
     */
    private static function keyed(array $counts): array
    {
        ksort($counts);

        return $counts;
    }
}
