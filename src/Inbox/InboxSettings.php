<?php

declare(strict_types=1);

namespace CattoPHP\Inbox;

use CattoPHP\Activity\Actor;
use CattoPHP\Foundation\Duration;
use CattoPHP\Settings\Settings;

/**
 * The Inbox's settings, declared by InboxSettingGroup (a class of its own, so reading settings here never builds the
 * settings catalogue from inside itself): how long the Trash, Spam and conversations are kept, the spam check's line and
 * what it learns from, the sender and signatures of replies, how the owner is told of a new message, and the mailbox
 * answers come to. As every setting, `.env` gives the starting value (INBOX_*) and Settings overrides it with a Reset;
 * lengths are written as everywhere (30d, 5y); the mailbox password is kept encrypted.
 */
final class InboxSettings
{
    public const GROUP = InboxSettingGroup::GROUP;

    /** The settings page's sections, in its order.  */
    public const SECTIONS = [
        'keeping' => ['INBOX_TRASH_KEPT_FOR', 'INBOX_SPAM_KEPT_FOR', 'INBOX_KEPT_FOR'],
        'spam' => ['INBOX_SPAM_LINE', 'INBOX_HOLD_BLOCKED_COUNTRIES', 'INBOX_LEARN', 'INBOX_NEVER_SPAM'],
        'replies' => ['INBOX_FROM_NAME', 'INBOX_FROM_ADDRESS', 'INBOX_REPLY_TO', 'INBOX_REPLY_FORMAT', 'INBOX_SIGNATURE_TEXT', 'INBOX_SIGNATURE_HTML'],
        'telling' => ['INBOX_NOTIFY_EMAIL', 'INBOX_NOTIFY_TELEGRAM', 'INBOX_ACKNOWLEDGE'],
        'receiving' => ['INBOX_IMAP_HOST', 'INBOX_IMAP_PORT', 'INBOX_IMAP_SECURITY', 'INBOX_IMAP_USER', 'INBOX_IMAP_PASSWORD'],
    ];

    /** The settings shown as ticks: on when ticked, off when not. */
    public const TICKS = ['INBOX_HOLD_BLOCKED_COUNTRIES', 'INBOX_LEARN', 'INBOX_NOTIFY_EMAIL', 'INBOX_NOTIFY_TELEGRAM', 'INBOX_ACKNOWLEDGE'];

    public function __construct(private readonly ?Settings $settings = null) {}

    public function get(string $name): string
    {
        return $this->settings?->get($name) ?? '';
    }

    private function on(string $name, bool $default): bool
    {
        $value = $this->get($name);
        return $value === '' ? $default : $value === 'on';
    }

    public function trashKeptFor(): Duration { return $this->duration('INBOX_TRASH_KEPT_FOR', '30d'); }
    public function spamKeptFor(): Duration { return $this->duration('INBOX_SPAM_KEPT_FOR', '30d'); }
    public function keptFor(): Duration { return $this->duration('INBOX_KEPT_FOR', '5y'); }

    private function duration(string $name, string $default): Duration
    {
        $value = $this->get($name);
        return Duration::parse(Duration::isValid($value) ? $value : $default);
    }

    /** The score at or above which a message goes to Spam. */
    public function spamLine(): int
    {
        $line = (int) $this->get('INBOX_SPAM_LINE');
        return $line >= 1 && $line <= 100 ? $line : 60;
    }

    public function holdsBlockedCountries(): bool { return $this->on('INBOX_HOLD_BLOCKED_COUNTRIES', true); }
    public function learns(): bool { return $this->on('INBOX_LEARN', true); }
    public function notifiesByEmail(): bool { return $this->on('INBOX_NOTIFY_EMAIL', true); }
    public function notifiesByTelegram(): bool { return $this->on('INBOX_NOTIFY_TELEGRAM', false); }
    public function acknowledges(): bool { return $this->on('INBOX_ACKNOWLEDGE', true); }

    /** @return list<string> the addresses and domains never sent to Spam, in lower case */
    public function neverSpam(): array
    {
        return array_values(array_filter(array_map(static fn(string $item): string => mb_strtolower(trim($item)), preg_split('/[\s,;]+/', $this->get('INBOX_NEVER_SPAM')) ?: [])));
    }

    public function replyFormat(): string
    {
        return $this->get('INBOX_REPLY_FORMAT') === 'plain' ? 'plain' : 'html';
    }

    public function signature(string $format): string
    {
        return $this->get($format === 'html' ? 'INBOX_SIGNATURE_HTML' : 'INBOX_SIGNATURE_TEXT');
    }

    /** @return array{name:string,address:string,reply_to:string} what replies are sent from; empty parts take the site's own */
    public function sender(): array
    {
        return ['name' => $this->get('INBOX_FROM_NAME'), 'address' => $this->get('INBOX_FROM_ADDRESS'), 'reply_to' => $this->get('INBOX_REPLY_TO')];
    }

    /** @return array{host:string,port:int,security:string,user:string,password:string} */
    public function mailbox(): array
    {
        return ['host' => $this->get('INBOX_IMAP_HOST'), 'port' => (int) ($this->get('INBOX_IMAP_PORT') ?: 993), 'security' => $this->get('INBOX_IMAP_SECURITY') ?: 'ssl',
            'user' => $this->get('INBOX_IMAP_USER'), 'password' => $this->get('INBOX_IMAP_PASSWORD')];
    }

    public function mailboxSet(): bool
    {
        $mailbox = $this->mailbox();
        return $mailbox['host'] !== '' && $mailbox['user'] !== '' && $mailbox['password'] !== '';
    }

    /**
     * Saves the Inbox settings page (one form, one Save, as the approved mockup): every setting as typed, each field named
     * as its setting in small letters (inbox_trash_kept_for), the password left blank keeping the one there is.
     *
     * @param array<string,mixed> $input
     * @throws \CattoPHP\Settings\SettingsRefused
     */
    public function saveAll(array $input, ?Actor $actor): void
    {
        $values = [];
        foreach (self::SECTIONS as $names) {
            foreach ($names as $name) {
                $field = strtolower($name);
                $typed = is_string($input[$field] ?? null) ? trim($input[$field]) : '';
                // A choice left unticked (an on/off tick) is off; a password left blank keeps the one there is.
                $values[$name] = match (true) {
                    $name === 'INBOX_IMAP_PASSWORD' => $typed === '' ? null : $typed,
                    in_array($name, self::TICKS, true) => $typed === 'on' ? 'on' : 'off',
                    default => $typed,
                };
            }
        }
        $this->settings?->save(self::GROUP, $values, $actor);
    }

    /** Back to the standard settings (Reset to the standard settings): `.env`'s values, else the standard ones. */
    public function resetAll(?Actor $actor = null): void
    {
        $this->settings?->resetGroup(self::GROUP, $actor);
    }

    /** Forgets the mailbox (Disconnect): its user and password emptied, so nothing is fetched. */
    public function forgetMailbox(?Actor $actor = null): void
    {
        foreach (['INBOX_IMAP_USER', 'INBOX_IMAP_PASSWORD', 'INBOX_IMAP_HOST'] as $name) $this->settings?->reset($name, $actor);
    }

    /**
     * Each setting as the Settings page shows it: its value (a secret only as whether it is set), whether Settings overrides
     * `.env`, and its label and help.
     *
     * @return array<string,array{value:string,overridden:bool,secret_set:bool}>
     */
    public function view(): array
    {
        $view = [];
        foreach (self::SECTIONS as $names) {
            foreach ($names as $name) {
                $secret = $name === 'INBOX_IMAP_PASSWORD';
                $view[$name] = ['value' => $secret ? '' : $this->get($name), 'overridden' => $this->settings?->isOverridden($name) ?? false, 'secret_set' => $secret && $this->get($name) !== ''];
            }
        }
        return $view;
    }
}
