<?php

declare(strict_types=1);

namespace CattoPHP\Telegram;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use CattoPHP\Links\LinkStore;
use CattoPHP\Security\SecretCipher;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * A person connects their Telegram account. A bot can only write to someone who has started it, so Connect Telegram makes a
 * link that works once for 10 minutes (CattoPHP's links, purpose telegram_connect) and a button to t.me/<bot>?start=<token>.
 * When the bot is sent /start <token> (TelegramInbox), the account is connected to that private chat, with its username if
 * it has one, and the bot says so. One Telegram account belongs to one person: a chat connected to someone else is refused,
 * with a plain answer from the bot and a plain message on the page. The token's words are kept in this browser's session
 * only, encrypted, so the page can show the button again until it is used. Disconnect forgets the account. Every change
 * goes into the activity log.
 */
class TelegramConnections
{
    public const PURPOSE = 'telegram_connect';
    public const TTL = 600;
    private const SESSION = 'telegram_connect';

    /** What the page says when a connection did not happen. */
    public const TAKEN = 'That Telegram account is already connected to another account here. Disconnect it there first, or use another Telegram account.';
    public const OTHER = 'Your account is already connected to another Telegram account. Disconnect it first.';
    public const EXPIRED = 'The link ran out before Telegram was started. Press Connect Telegram to try again.';
    public const NOT_OFFERED = 'Signing in with Telegram is not offered on this site.';

    public function __construct(
        private readonly LinkStore $links,
        private readonly TelegramAccounts $accounts,
        private readonly TelegramBot $bot,
        private readonly TelegramMessenger $messenger,
        private readonly TelegramWords $words,
        private readonly Connection $connection,
        private readonly SecretCipher $cipher,
        private readonly ClockInterface $clock,
        private readonly ?ActivityLog $activity = null,
    ) {
    }

    /**
     * Starts connecting: the button to the bot shows until Telegram is started or 10 minutes pass.
     *
     * @param string|null $requestedFrom where it was asked from, as the links count it
     */
    public function start(string $account, string $email, ?string $requestedFrom = null): void
    {
        if (!$this->bot->signInOffered() || $this->bot->botName() === '') throw new InvalidArgumentException(self::NOT_OFFERED);
        if ($this->accounts->forAccount($account) !== null) throw new InvalidArgumentException('Your Telegram account is connected already.');
        $raw = self::token();
        $now = $this->now();
        $this->connection->transactional(function () use ($account, $email, $raw, $requestedFrom, $now): void {
            $this->links->forgetOpen($account, self::PURPOSE);
            $this->links->add(hash('sha256', $raw), self::PURPOSE, $email, $account, [], $requestedFrom, $now, $now->modify('+' . self::TTL . ' seconds'));
        });
        $_SESSION[self::SESSION] = ['account' => $account, 'token' => $this->cipher->encrypt($raw), 'expires_at' => $now->getTimestamp() + self::TTL];
    }

    /** Stops connecting: the link no longer works. */
    public function cancel(string $account): void
    {
        $this->links->forgetOpen($account, self::PURPOSE);
        unset($_SESSION[self::SESSION]);
    }

    /**
     * Where a person's Telegram stands, for their account page: 'on' (connected), 'waiting' (the button to the bot, until it
     * is started) or 'off', with a note when a connection just failed.
     *
     * @return array{offered:bool,state:string,username:?string,connected_on:string,bot_name:string,link:string,note:?array{tone:string,words:string}}
     */
    public function view(string $account): array
    {
        $offered = $this->bot->signInOffered();
        $bot = $this->bot->botName();
        $base = ['offered' => $offered, 'state' => 'off', 'username' => null, 'connected_on' => '', 'bot_name' => $bot, 'link' => '', 'note' => null];
        $connected = $this->accounts->forAccount($account);
        if ($connected !== null) {
            unset($_SESSION[self::SESSION]);

            return ['state' => 'on', 'username' => $connected['username'], 'connected_on' => self::day($connected['connected_at'])] + $base;
        }
        $asked = $_SESSION[self::SESSION] ?? null;
        if (!is_array($asked) || (string) ($asked['account'] ?? '') !== $account) return $base;
        try {
            $raw = $this->cipher->decrypt((string) ($asked['token'] ?? ''));
        } catch (Throwable) {
            $raw = '';
        }
        $link = $raw === '' ? null : $this->links->find(hash('sha256', $raw), self::PURPOSE);
        if ($link === null || $link->usedAt !== null || (int) ($asked['expires_at'] ?? 0) < $this->now()->getTimestamp() || !$offered) {
            unset($_SESSION[self::SESSION]);
            $words = match ((string) ($link->context['refused'] ?? '')) {
                'taken' => self::TAKEN, 'other' => self::OTHER,
                default => $offered && $link !== null && $link->usedAt === null ? self::EXPIRED : null,
            };

            return ['note' => $words === null ? null : ['tone' => 'danger', 'words' => $words]] + $base;
        }

        return ['state' => 'waiting', 'link' => 'https://t.me/' . rawurlencode($bot) . '?start=' . rawurlencode($raw)] + $base;
    }

    /** The bot was sent /start with a token (TelegramInbox): the chat is connected to the token's account, or refused. */
    public function started(string $chatId, ?string $username, string $raw): void
    {
        /** @var array{0:string,1:?string} $outcome */
        $outcome = $this->connection->transactional(function () use ($chatId, $username, $raw): array {
            $link = $this->links->usable(hash('sha256', $raw), self::PURPOSE, $this->now(), true);
            if ($link === null || $link->accountId === null || $link->accountId === '') return ['expired', null];
            $account = $link->accountId;
            $taken = $this->accounts->forChat($chatId, true);
            $own = $this->accounts->forAccount($account);
            $refused = $taken !== null && $taken['account'] !== $account ? 'taken' : ($own !== null && $own['chat_id'] !== $chatId ? 'other' : null);
            $this->links->use($link->id, $this->now());
            $actor = new Actor($account, $account);
            if ($refused !== null) {
                $this->links->changeContext($link->id, ['refused' => $refused] + $link->context);
                $this->activity?->record('telegram.connect_refused', ['account_id' => $account, 'why' => $refused], actor: $actor);

                return [$refused, $account];
            }
            $this->accounts->connect($account, $chatId, $username);
            $this->activity?->record('telegram.connected', ['account_id' => $account, 'username' => TelegramAccounts::username($username)], actor: $actor);

            return ['connected', $account];
        });
        [$what] = $outcome;
        $domain = $this->words->domain();
        $words = match ($what) {
            'connected' => 'Your Telegram account is now connected to ' . $domain,
            'taken' => 'This Telegram account is already connected to another account on ' . $domain . '. Disconnect it there first.',
            'other' => 'Your account on ' . $domain . ' is already connected to another Telegram account. Disconnect that one first.',
            default => 'That link has run out or was used already. Go back to ' . $domain . ' and press Connect Telegram again.',
        };
        // The log names whose chat it is now: the person just connected, whoever holds it already, or nobody.
        $this->messenger->send('connected', $chatId, $this->accounts->forChat($chatId)['account'] ?? null, $username, $this->words->demo() . $words, '', [$raw],
            ($what === 'connected' ? 'connected' : 'reply') . ':' . bin2hex(random_bytes(8)));
    }

    /** Someone pressed Start on the bot without a token: one line saying what the bot is for. */
    public function greeted(string $chatId, ?string $username): void
    {
        $connected = $this->accounts->forChat($chatId);
        $this->messenger->send('connected', $chatId, $connected['account'] ?? null, $username,
            $this->words->demo() . 'This bot sends sign-in messages for ' . $this->words->domain() . '. To use it, sign in there, open Sign-in and security in your account and press Connect Telegram.');
    }

    /** A test to the person's own chat, from their page. Whether it went. */
    public function test(string $account): bool
    {
        $connected = $this->accounts->forAccount($account) ?? throw new InvalidArgumentException('Connect Telegram first.');

        return $this->messenger->send('test', $connected['chat_id'], $account, $connected['username'], $this->words->demo() . 'Test message from ' . $this->words->domain());
    }

    public function disconnect(string $account): void
    {
        $this->cancel($account);
        if ($this->accounts->disconnect($account)) $this->activity?->record('telegram.disconnected', ['account_id' => $account], actor: new Actor($account, $account));
    }

    /** A token a link carries: 32 random bytes, URL-safe. */
    private static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }

    private static function day(string $at): string
    {
        try {
            return (new DateTimeImmutable($at))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('j F Y');
        } catch (Throwable) {
            return '';
        }
    }
}
