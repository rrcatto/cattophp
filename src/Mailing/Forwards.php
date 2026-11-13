<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use CattoPHP\Mail\Mailer;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Forward to a friend (cattophp_mailing_forwards): a person sends their copy to a friend, who gets one copy through the
 * site's own mail (drawn by the project for the friend, with the person's note and a "Never email me" link) and is put on
 * no list. A copy goes to at most five friends, a person forwards at most ten a day, never to their own address, to an
 * address never emailed or to the same friend twice. Each forward is recorded in the activity log as mailing.forwarded.
 * The friend's "Never email me" puts their address on the addresses never emailed.
 */
final class Forwards
{
    public const TABLE = 'cattophp_mailing_forwards';
    public const PER_COPY = 5;
    public const PER_DAY = 10;

    public function __construct(
        private readonly Connection $connection,
        private readonly Suppressions $suppressions,
        private readonly Mailer $mailer,
        private readonly CampaignProject $project,
        private readonly ?ActivityLog $activity = null,
    ) {
    }

    /**
     * Forwards a person's copy to a friend. Returns the friend's address.
     *
     * @param array<string,mixed> $campaign
     * @param array<string,mixed> $copy
     */
    public function forward(array $campaign, array $copy, string $email, string $name, string $note): string
    {
        $account = is_scalar($copy['account'] ?? null) ? (string) $copy['account'] : '';
        if ($account === '') throw new InvalidArgumentException('This email can no longer be forwarded.');
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new InvalidArgumentException('Write your friend\'s email address in full, such as thandi@example.com.');
        $name = trim((string) preg_replace('/\s+/', ' ', $name));
        if (mb_strlen($name) > 120) throw new InvalidArgumentException('Keep your friend\'s name under 120 characters.');
        $note = trim(str_replace("\r\n", "\n", $note));
        if (mb_strlen($note) > 500) throw new InvalidArgumentException('Keep your note under 500 characters.');
        $copyId = (int) $copy['id'];
        if ($email === mb_strtolower(is_scalar($copy['email'] ?? null) ? (string) $copy['email'] : '')) throw new InvalidArgumentException('That is your own address. Write your friend\'s.');
        if ($this->suppressions->isSuppressed($email)) throw new InvalidArgumentException('We do not email that address.');
        if ($this->connection->fetchOne('SELECT 1 FROM ' . self::TABLE . ' WHERE copy_id = :copy AND to_email = :email', ['copy' => $copyId, 'email' => $email]) !== false) {
            throw new InvalidArgumentException('You already forwarded this email to ' . $email . '.');
        }
        if ($this->countForCopy($copyId) >= self::PER_COPY) throw new InvalidArgumentException('This email has been forwarded to ' . self::PER_COPY . ' friends already, the most it can be.');
        if ($this->countForAccountToday($account) >= self::PER_DAY) throw new InvalidArgumentException('You have forwarded ' . self::PER_DAY . ' emails today, the most in a day. Try again tomorrow.');
        $token = bin2hex(random_bytes(16));
        $drawn = $this->project->drawForFriend($campaign, $copy, $name, $note, $token);
        $this->mailer->send((new Email())->to($name !== '' ? new Address($email, $name) : $email)->subject($drawn->subject)->html($drawn->html)->text($drawn->text));
        $this->connection->executeStatement('INSERT INTO ' . self::TABLE . ' (copy_id, account, to_email, to_name, note, token) VALUES (:copy, :account, :email, :name, :note, :token)',
            ['copy' => $copyId, 'account' => $account, 'email' => $email, 'name' => $name, 'note' => $note, 'token' => $token]);
        $this->activity?->record('mailing.forwarded', ['delivery_id' => $copyId, 'campaign_id' => (int) ($copy['campaign_id'] ?? 0)], actor: new Actor($account, $account));

        return $email;
    }

    /** The friend's address a "Never email me" token names, or null. */
    public function emailByToken(string $token): ?string
    {
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) return null;
        $email = $this->connection->fetchOne('SELECT to_email FROM ' . self::TABLE . ' WHERE token = :token', ['token' => $token]);

        return is_string($email) ? $email : null;
    }

    /** The friend's "Never email me": their address goes on the addresses never emailed. Returns it. */
    public function neverEmail(string $token): string
    {
        $email = $this->emailByToken($token) ?? throw new InvalidArgumentException('This link is not known.');
        $this->suppressions->suppress($email, 'manual', 'Asked never to be emailed, from an email a friend forwarded.');

        return $email;
    }

    public function countForCopy(int $copyId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE copy_id = :copy', ['copy' => $copyId]);
    }

    /** How many a person forwarded in the past day. */
    public function countForAccountToday(string $account): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . " WHERE account = :account AND created_at > NOW() - INTERVAL '1 day'", ['account' => $account]);
    }

    /** How many times a campaign's copies were forwarded. */
    public function countForCampaign(int $campaignId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' f JOIN ' . Copies::TABLE . ' d ON d.id = f.copy_id WHERE d.campaign_id = :campaign', ['campaign' => $campaignId]);
    }

    /** A person whose account is gone: what they forwarded goes. */
    public function forget(string $account): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE account = :account', ['account' => $account]);
    }
}
