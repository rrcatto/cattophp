<?php

declare(strict_types=1);

namespace CattoPHP\Telegram;

use JsonException;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * What people send the bot. There is no webhook: a laptop site has no public address, and a bot can use only one of the
 * two. getUpdates is read from a saved offset, every minute by the owner_messages task and from a person's connect page
 * while it waits (at most once every 2 seconds), one reader at a time. It handles /start <token> (TelegramConnections
 * connects the chat), answers a bare /start with one line saying what the bot is for, keeps every connected person's
 * username up to date and ignores everything else. Only while people can sign in with Telegram: otherwise nothing here
 * reads or confirms anything. A message older than 10 minutes, the life of a connect link, is read but never answered.
 * Reading from an offset uses the updates up, and connecting the owner looks for their chat among recent ones, so the
 * private chats seen lately (24 hours, the last 50) are kept beside the offset. A bot serves one site for sign-in: two
 * sites reading one bot's updates would each use up the other's.
 */
class TelegramInbox
{
    public const SEEN_FOR = 86400;
    public const SEEN_KEPT = 50;
    public const EVERY = 2;
    /** How old a message may be and still be answered: a connect link's life. */
    public const ANSWERS_FOR = 600;

    public function __construct(
        private readonly TelegramBot $settings,
        private readonly TelegramState $state,
        private readonly TelegramClient $telegram,
        private readonly TelegramAccounts $accounts,
        private readonly TelegramConnections $connections,
        private readonly TelegramLog $messages,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Reads what the bot has been sent since last time and handles it. How many messages were read; nothing when sign-in by
     * Telegram is off, another reader is at it, or (for a page) it was read under 2 seconds ago.
     *
     * @throws TelegramFailed when Telegram does not answer, for the job to say why
     */
    public function read(bool $forAPage = false): int
    {
        if (!$this->settings->signInOffered()) return 0;
        if (!$this->messages->takeInboxTurn()) return 0;
        try {
            $state = $this->state();
            $now = $this->clock->now()->getTimestamp();
            if ($forAPage && $now - $state['read_at'] < self::EVERY) return 0;
            $state['read_at'] = $now;
            $this->save($state);
            $updates = $this->telegram->updates($this->settings->address(), $this->settings->key(), $state['offset']);
            foreach ($updates as $update) {
                $id = (int) ($update['update_id'] ?? 0);
                if ($id >= $state['offset']) $state['offset'] = $id + 1;
                $state['seen'] = $this->handle(is_array($update['message'] ?? null) ? $update['message'] : [], $state['seen'], $now);
                // Saved after each, so a fault part way never handles one twice.
                $this->save($state);
            }

            return count($updates);
        } finally {
            $this->messages->endInboxTurn();
        }
    }

    /** The private chat of this username among those seen lately, newest first, or null. */
    public function seenChat(string $username): ?string
    {
        $username = ltrim(trim($username), '@');
        if ($username === '') return null;
        foreach ($this->state()['seen'] as $chat) {
            if (strcasecmp($chat['username'], $username) === 0) return $chat['chat'];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $message
     * @param list<array{chat:string,username:string,at:int}> $seen
     * @return list<array{chat:string,username:string,at:int}>
     */
    private function handle(array $message, array $seen, int $now): array
    {
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        if (($chat['type'] ?? '') !== 'private' || !isset($chat['id']) || !is_scalar($chat['id'])) return $seen;
        $chatId = (string) $chat['id'];
        if (preg_match('/^-?\d{1,20}$/', $chatId) !== 1) return $seen;
        $username = TelegramAccounts::username(is_string($from['username'] ?? null) ? $from['username'] : null);
        // Who wrote, newest first, for Connect to Telegram at Settings.
        $seen = array_values(array_filter($seen, static fn(array $one): bool => $one['chat'] !== $chatId && $one['at'] > $now - self::SEEN_FOR));
        if ($username !== null) array_unshift($seen, ['chat' => $chatId, 'username' => $username, 'at' => $now]);
        $seen = array_slice($seen, 0, self::SEEN_KEPT);
        // A connected person's username follows what Telegram calls them now.
        $this->accounts->heard($chatId, $username);
        $text = trim(is_string($message['text'] ?? null) ? $message['text'] : '');
        // Read, and never answered: a message from before the last 10 minutes.
        if ((int) ($message['date'] ?? $now) < $now - self::ANSWERS_FOR) return $seen;
        try {
            if (preg_match('#^/start(?:@\w+)?\s+([A-Za-z0-9_-]{16,64})$#', $text, $match) === 1) {
                $this->connections->started($chatId, $username, $match[1]);
            } elseif (preg_match('#^/start(?:@\w+)?$#', $text) === 1) {
                $this->connections->greeted($chatId, $username);
            }
        } catch (Throwable $fault) {
            // One message that cannot be handled never stops the others; nothing it held is written down.
            error_log('A message to the Telegram bot could not be handled: ' . $fault::class);
        }

        return $seen;
    }

    /** @return array{bot:string,offset:int,read_at:int,seen:list<array{chat:string,username:string,at:int}>} */
    private function state(): array
    {
        try {
            $saved = json_decode((string) ($this->state->get(TelegramState::INBOX) ?? '{}'), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $saved = [];
        }
        if (!is_array($saved)) $saved = [];
        $bot = $this->settings->botName();
        // Another bot has its own updates, counted from its own start.
        if ((string) ($saved['bot'] ?? '') !== $bot) $saved = [];
        $seen = [];
        foreach (is_array($saved['seen'] ?? null) ? $saved['seen'] : [] as $one) {
            if (is_array($one) && isset($one['chat'], $one['username'], $one['at'])) $seen[] = ['chat' => (string) $one['chat'], 'username' => (string) $one['username'], 'at' => (int) $one['at']];
        }

        return ['bot' => $bot, 'offset' => max(0, (int) ($saved['offset'] ?? 0)), 'read_at' => (int) ($saved['read_at'] ?? 0), 'seen' => $seen];
    }

    /** @param array{bot:string,offset:int,read_at:int,seen:list<array{chat:string,username:string,at:int}>} $state */
    private function save(array $state): void
    {
        $this->state->set(TelegramState::INBOX, json_encode($state, JSON_THROW_ON_ERROR));
    }
}
