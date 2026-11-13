<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

use CattoPHP\Security\KeyedHash;
use DateTimeImmutable;

/**
 * Where each connection to an outside service stands, by the connection's name (`didit`, `stitch`), from its last real
 * try: a connection exists only once Check the connection established it, never because its details are filled in
 * A try records when it was made, whether it worked, what went wrong in the service's
 * words, anything it learnt (a workflow's name), and a keyed fingerprint of the details it was made with: details changed
 * since, by the Settings page or in .env, take the connection back to not checked, since the try says nothing about them.
 *
 * The states: `none` (no details), `unchecked` (details never tried, or changed since), `failed` (the last try did not
 * work) and `connected`.
 */
final class ServiceConnections
{
    public const NONE = 'none';
    public const UNCHECKED = 'unchecked';
    public const FAILED = 'failed';
    public const CONNECTED = 'connected';

    /** What the service said is kept to this length. */
    public const LONGEST_ERROR = 300;

    public function __construct(private readonly ConnectionStore $store, private readonly KeyedHash $hash)
    {
    }

    /**
     * Records the outcome of a real try with $details, the values it was made with.
     *
     * @param list<string> $details
     * @param array<string,string> $learnt anything the try found out, kept beside the state
     */
    public function record(string $name, array $details, bool $connected, string $error = '', array $learnt = []): void
    {
        $this->store->put($name, [
            'checked_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'connected' => $connected,
            'error' => $connected ? '' : mb_substr($error !== '' ? $error : 'The service did not say why.', 0, self::LONGEST_ERROR),
            'for' => $this->fingerprint($name, $details),
        ] + $learnt);
    }

    /**
     * Where the connection stands with the details in force now: `none` when every one of them is empty.
     *
     * @param list<string> $details
     * @return array{state:string,checked_at:string,error:string,learnt:array<string,string>}
     */
    public function status(string $name, array $details): array
    {
        $saved = $this->store->get($name);
        $text = static fn(string $key): string => is_scalar($saved[$key] ?? null) ? (string) $saved[$key] : '';
        $learnt = [];
        foreach ($saved as $key => $value) {
            if (!in_array($key, ['checked_at', 'connected', 'error', 'for'], true) && is_scalar($value)) {
                $learnt[$key] = (string) $value;
            }
        }
        // A state kept before tries were fingerprinted (moved from an older table) is taken as made with these details.
        $sameDetails = $text('for') === '' || hash_equals($text('for'), $this->fingerprint($name, $details));
        $state = match (true) {
            array_filter($details, static fn(string $detail): bool => trim($detail) !== '') === [] => self::NONE,
            $saved === [] || !$sameDetails => self::UNCHECKED,
            $text('error') !== '' => self::FAILED,
            ($saved['connected'] ?? null) === true => self::CONNECTED,
            default => self::UNCHECKED,
        };

        return [
            'state' => $state,
            'checked_at' => $state === self::NONE || !$sameDetails ? '' : $text('checked_at'),
            'error' => $state === self::FAILED ? $text('error') : '',
            'learnt' => $sameDetails ? $learnt : [],
        ];
    }

    /** @param list<string> $details */
    public function connected(string $name, array $details): bool
    {
        return $this->status($name, $details)['state'] === self::CONNECTED;
    }

    /** Forgets what the last try found: the connection is not checked again until Check the connection. */
    public function forget(string $name): void
    {
        $this->store->remove($name);
    }

    /** @param list<string> $details */
    private function fingerprint(string $name, array $details): string
    {
        return $this->hash->of('connection:' . $name, json_encode(array_map('trim', $details), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
