<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

use CattoPHP\Activity\Actor;
use CattoPHP\Settings\Settings;
use InvalidArgumentException;

/**
 * Where news is sent from and through (Settings > Mailing): the sender, the address its links point to, the transport
 * (a mail server or catto-mail), the mail server, and catto-mail's address, key and webhook secrets. `.env` gives each
 * starting value (MAILING_*, CATTOMAIL_*) and Settings overrides it with a Reset; the secrets are kept encrypted. An empty
 * secret in a form keeps the one saved, so it never has to be typed again to change something else.
 */
final class MailingSettings
{
    /** The settings: key => [.env name, label, secret]. */
    public const FIELDS = [
        'from_address' => ['MAILING_FROM_ADDRESS', 'Sender address', false],
        'from_name' => ['MAILING_FROM_NAME', 'Sender name', false],
        'link_base' => ['MAILING_LINK_BASE', 'Address of the links', false],
        'transport' => ['MAILING_TRANSPORT', 'Sent through', false],
        'dsn' => ['MAILING_DSN', 'Mail server (SMTP)', true],
        'cattomail_url' => ['CATTOMAIL_API_BASE_URL', 'cattomail address', false],
        'cattomail_key' => ['CATTOMAIL_API_KEY', 'cattomail API key', true],
        'cattomail_secret' => ['CATTOMAIL_WEBHOOK_SECRET', 'cattomail webhook secret', true],
        'cattomail_secret_previous' => ['CATTOMAIL_WEBHOOK_SECRET_PREVIOUS', 'Previous webhook secret', true],
    ];

    public const TRANSPORTS = ['smtp' => 'A mail server (SMTP)', 'cattomail' => 'catto-mail'];

    /** @param Settings|null $settings null reads nothing (a project without settings) */
    public function __construct(private readonly ?Settings $settings = null)
    {
    }

    /** A setting's value: what Settings saved, else .env, else empty. */
    public function value(string $field): string
    {
        return trim($this->settings?->get(self::name($field)) ?? '');
    }

    /** What .env gives for it. */
    public function environmentValue(string $field): string
    {
        return trim($this->settings?->envValue(self::name($field)) ?? '');
    }

    /** 'database' when Settings saved it, else 'environment'. */
    public function source(string $field): string
    {
        return $this->settings?->isOverridden(self::name($field)) === true ? 'database' : 'environment';
    }

    /** smtp or cattomail; smtp when nothing usable is set. */
    public function transport(): string
    {
        $transport = strtolower($this->value('transport'));

        return isset(self::TRANSPORTS[$transport]) ? $transport : 'smtp';
    }

    /** The address news comes from; the site's own sender when none is set. */
    public function fromAddress(string $fallback): string
    {
        $address = $this->value('from_address');

        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : $fallback;
    }

    public function fromName(string $fallback): string
    {
        return $this->value('from_name') !== '' ? $this->value('from_name') : $fallback;
    }

    /** Where a copy's links point, without a closing slash: the news address, else the site's. */
    public function linkBase(string $fallback): string
    {
        $base = rtrim($this->value('link_base'), '/');

        return preg_match('#^https?://[^/\s]+$#', $base) === 1 ? $base : rtrim($fallback, '/');
    }

    /**
     * Saves the settings given (only those present), each then overriding .env; an empty secret keeps the one saved.
     *
     * @param array<string,string> $input
     * @return list<string> the labels of what changed
     */
    public function save(array $input, string $actor = ''): array
    {
        if ($this->settings === null) throw new InvalidArgumentException('Settings cannot be saved here.');
        $changed = [];
        foreach (self::FIELDS as $field => [$name, $label, $secret]) {
            if (!array_key_exists($field, $input)) continue;
            $value = trim((string) $input[$field]);
            if ($secret && $value === '') continue;
            self::check($field, $value);
            if (!$secret && $value === $this->value($field) && $this->source($field) === 'database') continue;
            $this->settings->set($name, $value, $actor !== '' && $actor !== '0' ? new Actor($actor, $actor) : null);
            $changed[] = $label;
        }

        return $changed;
    }

    /**
     * Removes what was saved for these settings only, so they follow .env again (forgetting a catto-mail connection).
     *
     * @param list<string> $fields
     */
    public function forget(array $fields): void
    {
        foreach ($fields as $field) if (isset(self::FIELDS[$field])) $this->settings?->reset(self::FIELDS[$field][0]);
    }

    /** Removes what was saved for the sender and transport: they follow .env again. A catto-mail connection is not touched. */
    public function reset(): void
    {
        foreach (array_keys(self::FIELDS) as $field) if (!str_starts_with($field, 'cattomail_')) $this->settings?->reset(self::FIELDS[$field][0]);
    }

    private static function name(string $field): string
    {
        return self::FIELDS[$field][0] ?? throw new InvalidArgumentException('Unknown mailing setting.');
    }

    private static function check(string $field, string $value): void
    {
        if ($value === '') return;
        $fine = match ($field) {
            'from_address' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'from_name' => mb_strlen($value) <= 120,
            'link_base' => preg_match('#^https?://[^/\s]+/?$#', $value) === 1,
            'cattomail_url' => preg_match('#^https://[^\s]+$#', $value) === 1,
            'transport' => isset(self::TRANSPORTS[strtolower($value)]),
            'dsn' => preg_match('#^[a-z0-9+]+://#i', $value) === 1,
            default => mb_strlen($value) <= 500,
        };
        if (!$fine) throw new InvalidArgumentException(match ($field) {
            'from_address' => 'Enter the sender address as an email address, such as news@news.example.test.',
            'link_base' => 'Enter the address of the links without a path, such as https://news.example.test.',
            'cattomail_url' => 'Enter the cattomail address with https://, such as https://mail.example.test/v1.',
            'transport' => 'Choose a mail server (SMTP) or cattomail.',
            'dsn' => 'Enter the mail server as a DSN, such as smtp://user:password@smtp.example.test:587.',
            default => self::FIELDS[$field][1] . ' is too long.',
        });
    }
}
