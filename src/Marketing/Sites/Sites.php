<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sites;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use CattoPHP\Foundation\Duration;
use CattoPHP\Installation\Installation;
use CattoPHP\Marketing\MarketingRefused;
use CattoPHP\Marketing\MarketingSettings;
use CattoPHP\Marketing\Rows;
use CattoPHP\Security\SecretCipher;
use CattoPHP\Webhooks\WebhookSignature;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * The other sites this one is connected to, so one campaign can run across both (Marketing > Settings, Connected sites):
 * each added with its name, its https:// address and a shared secret (stored encrypted by Security\SecretCipher, never
 * shown again), renamed or given another address, given a new secret (the previous one kept and accepted beside it until
 * a message from the other site checks with the new one), and removed, which keeps it, marked removed, so the touches and
 * sales that name it keep its name. What happened last is kept with it: when this site sent to it, heard from it and
 * checked the connection, how far apart the two clocks were, the last problem sending to it and the last arrival or
 * message from it that was refused, each in words. Every change is in the activity log (marketing.site.*).
 *
 * Messages between the two are signed as webhooks are (Webhooks\WebhookSignature), with the current and the previous
 * secret while a new one is being put in, and accepted only within MARKETING_SITE_TOLERANCE of this site's clock.
 */
final class Sites
{
    public const TABLE = 'cattophp_marketing_sites';

    /** A shared secret: 32 to 200 characters that can be typed, with no spaces. */
    public const SECRET = '/^[\x21-\x7e]{32,200}$/';

    /** What Settings shows for a secret that is saved. */
    public const SECRET_SHOWN = '************';

    public function __construct(
        private readonly Connection $connection,
        private readonly SecretCipher $cipher,
        private readonly MarketingSettings $settings,
        private readonly ClockInterface $clock,
        private readonly ?ActivityLog $activity = null,
        private readonly ?Installation $installation = null,
    ) {
    }

    /** @return list<Site> the sites this one is connected to, not those removed, by name */
    public function all(): array
    {
        return array_map(self::site(...), $this->connection->fetchAllAssociative('SELECT * FROM ' . self::TABLE . ' WHERE removed_at IS NULL ORDER BY lower(name), key'));
    }

    /** A connected site by its key, or null when there is none or it was removed. */
    public function get(string $key): ?Site
    {
        $site = $this->find($key);

        return $site === null || $site->isRemoved() ? null : $site;
    }

    /** A site by its key, removed ones too (how the records name it), or null. */
    public function find(string $key): ?Site
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE key = :key', ['key' => $key]);

        return $row === false ? null : self::site($row);
    }

    /** The name a site's key stands for in the records, removed sites too; the key itself when none is known. */
    public function name(string $key): string
    {
        return $this->find($key)->name ?? $key;
    }

    /** The connected site at an address (as another site names itself), or null. */
    public function byAddress(string $address): ?Site
    {
        $address = self::normalise($address);
        if ($address === null) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE removed_at IS NULL AND lower(address) = :address', ['address' => strtolower($address)]);

        return $row === false ? null : self::site($row);
    }

    /** The connected site whose address has this host, such as example.org, or null: how a destination on it is recognised. */
    public function forHost(string $host): ?Site
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }
        foreach ($this->all() as $site) {
            if ($site->host() === $host) {
                return $site;
            }
        }

        return null;
    }

    /**
     * Connects another site. A site removed before at the same address is connected again under its old key, so the
     * records that name it are its again.
     *
     * @throws MarketingRefused with why beside each field (name, address, secret)
     */
    public function add(string $name, string $address, #[\SensitiveParameter] string $secret, ?Actor $actor = null): Site
    {
        $name = Rows::typed($name);
        $secret = Rows::typed($secret);
        $errors = $this->problems($name, $address, null);
        if (preg_match(self::SECRET, $secret) !== 1) {
            $errors['secret'] = 'A shared secret is 32 to 200 characters with no spaces. Press Make a secret for one, or paste the one the other site made.';
        }
        $normal = self::normalise($address) ?? '';
        $key = Rows::slug($name, 40);
        if ($key === '' && !isset($errors['name'])) {
            $errors['name'] = 'A site\'s name has at least one letter or digit, such as Example Shop.';
        }
        if ($errors !== []) {
            throw new MarketingRefused($errors);
        }
        $now = $this->now();
        $removed = $this->connection->fetchOne('SELECT key FROM ' . self::TABLE . ' WHERE removed_at IS NOT NULL AND lower(address) = :address ORDER BY removed_at DESC LIMIT 1',
            ['address' => strtolower($normal)]);
        $values = ['name' => $name, 'address' => $normal, 'secret' => $this->cipher->encrypt($secret), 'now' => Rows::sql($now), 'by' => $actor?->id];
        if (is_string($removed)) {
            $key = $removed;
            $this->connection->executeStatement(
                'UPDATE ' . self::TABLE . ' SET name = :name, address = :address, secret = :secret, previous_secret = NULL, last_sent_at = NULL, last_received_at = NULL,
                 last_checked_at = NULL, clock_apart_seconds = NULL, last_problem = NULL, last_problem_at = NULL, last_refused = NULL, last_refused_at = NULL, created_by = :by,
                 created_at = :now, updated_at = :now, removed_at = NULL WHERE key = :key',
                $values + ['key' => $key],
            );
        } else {
            $key = $this->freeKey($key);
            $this->connection->executeStatement(
                'INSERT INTO ' . self::TABLE . ' (key, name, address, secret, created_by, created_at, updated_at) VALUES (:key, :name, :address, :secret, :by, :now, :now)',
                $values + ['key' => $key],
            );
        }
        $site = $this->get($key) ?? throw MarketingRefused::because('The connected site could not be read back.');
        $this->log('marketing.site.added', $site, ['name' => $site->name, 'address' => $site->address], $actor);

        return $site;
    }

    /**
     * Renames a connected site or gives it another address; its key, its secret and its records stay.
     *
     * @throws MarketingRefused
     */
    public function change(string $key, string $name, string $address, ?Actor $actor = null): Site
    {
        $site = $this->require($key);
        $name = Rows::typed($name);
        $errors = $this->problems($name, $address, $site);
        if ($errors !== []) {
            throw new MarketingRefused($errors);
        }
        $normal = self::normalise($address) ?? '';
        if ($name === $site->name && $normal === $site->address) {
            return $site;
        }
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET name = :name, address = :address, updated_at = :now WHERE key = :key',
            ['name' => $name, 'address' => $normal, 'now' => Rows::sql($this->now()), 'key' => $site->key]);
        $changed = $this->require($site->key);
        $details = ['name' => $changed->name, 'address' => $changed->address];
        if ($site->name !== $changed->name) {
            $details['name_before'] = $site->name;
        }
        if ($site->address !== $changed->address) {
            $details['address_before'] = $site->address;
        }
        $this->log('marketing.site.changed', $changed, $details, $actor);

        return $changed;
    }

    /**
     * Gives a connected site a new shared secret. The one it had is kept as the previous secret, signing and checking
     * beside the new one, until a message from the other site checks with the new one, when it is dropped by itself.
     *
     * @throws MarketingRefused
     */
    public function changeSecret(string $key, #[\SensitiveParameter] string $secret, ?Actor $actor = null): Site
    {
        $site = $this->require($key);
        $secret = Rows::typed($secret);
        if (preg_match(self::SECRET, $secret) !== 1) {
            throw new MarketingRefused(['secret' => 'A shared secret is 32 to 200 characters with no spaces. Press Make a secret for one, or paste the one the other site made.']);
        }
        try {
            $same = $this->cipher->decrypt($site->secret) === $secret;
        } catch (\RuntimeException) {
            // The secret it had cannot be read (the application key changed): the new one replaces it, with no previous one.
            $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET secret = :secret, previous_secret = NULL, updated_at = :now WHERE key = :key',
                ['secret' => $this->cipher->encrypt($secret), 'now' => Rows::sql($this->now()), 'key' => $site->key]);
            $this->log('marketing.site.secret_changed', $site, ['site' => $site->name], $actor);

            return $this->require($site->key);
        }
        if ($same) {
            throw new MarketingRefused(['secret' => 'That is the secret it has already. Make a new one, or paste the new one the other site made.']);
        }
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET previous_secret = secret, secret = :secret, updated_at = :now WHERE key = :key',
            ['secret' => $this->cipher->encrypt($secret), 'now' => Rows::sql($this->now()), 'key' => $site->key]);
        $this->log('marketing.site.secret_changed', $site, ['site' => $site->name], $actor);

        return $this->require($site->key);
    }

    /**
     * Removes a connected site: refused while reports to it are not delivered, unless they are to be dropped. It is kept,
     * marked removed, so its touches and sales keep its name; its arrivals and messages are refused from now on. Gives how
     * many reports were dropped.
     *
     * @throws MarketingRefused
     */
    public function remove(string $key, bool $dropUndelivered, ?Actor $actor = null): int
    {
        $site = $this->require($key);

        return $this->connection->transactional(function (Connection $connection) use ($site, $dropUndelivered, $actor): int {
            $undelivered = (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . SiteReports::OUT . ' WHERE site_key = :key AND delivered_at IS NULL', ['key' => $site->key]);
            if ($undelivered > 0 && !$dropUndelivered) {
                throw MarketingRefused::because(sprintf('%s has %s not delivered yet, so it was not removed. Wait until %s delivered, or choose Remove it and drop the waiting reports.',
                    $site->name, self::reports($undelivered), $undelivered === 1 ? 'it is' : 'they are'));
            }
            $dropped = $undelivered === 0 ? 0 : (int) $connection->executeStatement('DELETE FROM ' . SiteReports::OUT . ' WHERE site_key = :key AND delivered_at IS NULL', ['key' => $site->key]);
            $connection->executeStatement('UPDATE ' . self::TABLE . ' SET removed_at = :now, updated_at = :now WHERE key = :key', ['now' => Rows::sql($this->now()), 'key' => $site->key]);
            $this->log('marketing.site.removed', $site, ['name' => $site->name, 'address' => $site->address] + ($dropped > 0 ? ['reports_dropped' => $dropped] : []), $actor);

            return $dropped;
        });
    }

    /**
     * The secrets to sign with, the current one first and the previous one beside it while a new one is being put in.
     *
     * @return list<string>
     * @throws \RuntimeException when a secret cannot be read (the application key is not the one it was saved with)
     */
    public function secrets(Site $site): array
    {
        $secrets = [$this->cipher->decrypt($site->secret)];
        if ($site->isChangingSecret()) {
            $secrets[] = $this->cipher->decrypt((string) $site->previousSecret);
        }

        return array_values(array_filter($secrets, static fn (string $secret): bool => $secret !== ''));
    }

    /**
     * A body signed for a site at a moment: the X-CattoPHP-Signature header, or the cattophp_sig value of a forward.
     *
     * @throws \RuntimeException when its secret cannot be read
     */
    public function sign(Site $site, string $body, DateTimeImmutable $at): string
    {
        return WebhookSignature::sign($body, $this->secrets($site), $at->getTimestamp());
    }

    /**
     * Checks a body a site signed: its signature with the site's secret (or the previous one while a new one is being put
     * in) and its time within MARKETING_SITE_TOLERANCE of now. One that checks with the new secret drops the previous one,
     * since the other site has the new one. Nothing is noted here; the caller notes a refusal (refused()).
     *
     * @throws SiteRefused saying why, for this site's administrator only
     */
    public function verify(Site $site, string $header, string $body, DateTimeImmutable $now): void
    {
        try {
            $current = $this->cipher->decrypt($site->secret);
            $previous = $site->isChangingSecret() ? $this->cipher->decrypt((string) $site->previousSecret) : '';
        } catch (\RuntimeException) {
            throw new SiteRefused('its shared secret here could not be read, so put the secret in again', $site);
        }
        $time = self::signedAt($header);
        $byCurrent = $current !== '' && WebhookSignature::valid($header, $body, [$current], $time ?? 0, PHP_INT_MAX);
        $byPrevious = !$byCurrent && $previous !== '' && WebhookSignature::valid($header, $body, [$previous], $time ?? 0, PHP_INT_MAX);
        if ($time === null || (!$byCurrent && !$byPrevious)) {
            throw new SiteRefused('its signature did not match', $site);
        }
        $apart = abs($now->getTimestamp() - $time);
        if ($apart > $this->settings->siteTolerance()->lengthInSeconds()) {
            throw new SiteRefused(sprintf('its time was %s from this site\'s', self::apart($apart)), $site);
        }
        if ($byCurrent && $site->isChangingSecret()) {
            $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET previous_secret = NULL, updated_at = :now WHERE key = :key', ['now' => Rows::sql($now), 'key' => $site->key]);
        }
    }

    /** Notes a refused arrival or message from a site for its card: what it was and why, as "arrival: its signature did not match.". */
    public function refused(Site $site, string $why, DateTimeImmutable $at): void
    {
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET last_refused = :why, last_refused_at = :at WHERE key = :key',
            ['why' => mb_substr($why, 0, 300), 'at' => Rows::sql($at), 'key' => $site->key]);
    }

    /** Notes that something signed by a site was accepted here. */
    public function heardFrom(Site $site, DateTimeImmutable $at): void
    {
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET last_received_at = :at WHERE key = :key', ['at' => Rows::sql($at), 'key' => $site->key]);
    }

    /** Notes reports delivered to a site: its problem, if any, is over. */
    public function sentTo(Site $site, DateTimeImmutable $at, ?int $clockApart): void
    {
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET last_sent_at = :at, last_problem = NULL, last_problem_at = NULL, clock_apart_seconds = COALESCE(:apart, clock_apart_seconds)
             WHERE key = :key', ['at' => Rows::sql($at), 'apart' => $clockApart, 'key' => $site->key]);
    }

    /** Notes a problem reaching a site, in words for its card. */
    public function problem(Site $site, string $problem, DateTimeImmutable $at, ?int $clockApart = null): void
    {
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET last_problem = :problem, last_problem_at = :at, clock_apart_seconds = COALESCE(:apart, clock_apart_seconds) WHERE key = :key',
            ['problem' => mb_substr($problem, 0, 300), 'at' => Rows::sql($at), 'apart' => $clockApart, 'key' => $site->key]);
    }

    /** Notes a check of the connection that was accepted, and how far apart the clocks were. */
    public function checked(Site $site, DateTimeImmutable $at, ?int $clockApart): void
    {
        $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET last_checked_at = :at, clock_apart_seconds = :apart, last_problem = NULL, last_problem_at = NULL WHERE key = :key',
            ['at' => Rows::sql($at), 'apart' => $clockApart, 'key' => $site->key]);
    }

    /** Records a check of the connection in the activity log, with its result in words. */
    public function logCheck(Site $site, string $result, ?int $clockApart, ?Actor $actor): void
    {
        $this->log('marketing.site.checked', $site, ['name' => $site->name, 'result' => $result] + ($clockApart === null ? [] : ['clock_apart_seconds' => $clockApart]), $actor);
    }

    /** A new shared secret: 32 random bytes as 43 letters, digits, hyphens and underscores, to copy to the other site. */
    public static function makeSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** An address as a site's is kept: https://, its host in small letters, no slash at the end; null when it is not one. */
    public static function normalise(string $address): ?string
    {
        $address = trim($address);
        if (mb_strlen($address) > 300 || preg_match('#^https://[^/\s?\#]+(/[^\s?\#]*)?$#i', $address) !== 1) {
            return null;
        }
        $parts = parse_url($address);
        if ($parts === false || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return rtrim('https://' . strtolower($parts['host']) . $port . ($parts['path'] ?? ''), '/');
    }

    /** How far apart two moments are, in words: "40 seconds", "7 minutes", "2 hours 5 minutes". */
    public static function apart(int $seconds): string
    {
        $seconds = abs($seconds);
        if ($seconds >= 60) {
            $seconds = (int) (round($seconds / 60) * 60);
        }

        return $seconds < 1 ? 'no time' : Duration::ofSeconds($seconds)->words();
    }

    /** "1 report", "3 reports". */
    public static function reports(int $count): string
    {
        return number_format($count) . ($count === 1 ? ' report' : ' reports');
    }

    /**
     * The time a signature header says it was made, or null when it gives none or more than one (so a fresh time put in
     * front of an old signature can never pass for the one it was signed at).
     */
    private static function signedAt(string $header): ?int
    {
        $times = [];
        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($name === 't') {
                $times[] = $value;
            }
        }

        return count($times) === 1 && preg_match('/^\d{1,12}$/', $times[0]) === 1 ? (int) $times[0] : null;
    }

    /** @return array<string,string> why the name or address cannot be a connected site's, by field */
    private function problems(string $name, string $address, ?Site $except): array
    {
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'A site\'s name is 1 to 120 characters, such as Example Shop.';
        }
        $normal = self::normalise($address);
        if ($normal === null) {
            $errors['address'] = 'Its address starts with https:// and is the other site\'s own, such as https://example.org, with no spaces, ? or #.';
        } elseif ($this->installation !== null && $this->installation->address() !== '' && strtolower($normal) === strtolower(rtrim($this->installation->address(), '/'))) {
            $errors['address'] = 'That is this site\'s own address: give the other site\'s.';
        } else {
            $taken = $this->byAddress($normal);
            if ($taken !== null && $taken->key !== $except?->key) {
                $errors['address'] = sprintf('%s is connected at that address already.', $taken->name);
            }
        }

        return $errors;
    }

    private function freeKey(string $key): string
    {
        $candidate = $key;
        for ($n = 2; $this->find($candidate) !== null; $n++) {
            $candidate = rtrim(substr($key, 0, 40 - strlen((string) $n) - 1), '-') . '-' . $n;
        }

        return $candidate;
    }

    private function require(string $key): Site
    {
        return $this->get($key) ?? throw MarketingRefused::because('There is no such connected site.');
    }

    /** @param array<string,mixed> $details */
    private function log(string $action, Site $site, array $details, ?Actor $actor): void
    {
        $this->activity?->record($action, $details, Site::SUBJECT, $site->key, $actor);
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    /** @param array<string,mixed> $row */
    private static function site(array $row): Site
    {
        $utc = new DateTimeZone('UTC');

        return new Site(Rows::text($row['key']), Rows::text($row['name']), Rows::text($row['address']), Rows::text($row['secret']), Rows::nullableText($row['previous_secret']),
            Rows::instant($row['last_sent_at'], $utc), Rows::instant($row['last_received_at'], $utc), Rows::instant($row['last_checked_at'], $utc),
            Rows::nullableInt($row['clock_apart_seconds']), Rows::nullableText($row['last_problem']), Rows::instant($row['last_problem_at'], $utc), Rows::nullableText($row['last_refused']),
            Rows::instant($row['last_refused_at'], $utc), Rows::nullableText($row['created_by']), Rows::instant($row['created_at'], $utc), Rows::instant($row['removed_at'], $utc));
    }
}
