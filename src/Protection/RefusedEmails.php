<?php

declare(strict_types=1);

namespace CattoPHP\Protection;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;

/** Email addresses and domains refused by every guarded form, from any address (cattophp_site_refused_emails). */
class RefusedEmails
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->connection->fetchAllAssociative('SELECT id, value, why, refused, created_by, created_at FROM cattophp_site_refused_emails ORDER BY created_at DESC, id DESC');
    }

    /** Whether this email address, or its domain, is on the list. Counted when it is. */
    public function refuses(string $email): bool
    {
        $email = strtolower(trim($email));
        $domain = (string) substr((string) strrchr($email, '@'), 1);

        return $this->connection->executeStatement('UPDATE cattophp_site_refused_emails SET refused = refused + 1 WHERE value IN (:email, :domain)', ['email' => $email, 'domain' => $domain !== '' ? $domain : $email]) > 0;
    }

    /** @param string|null $by who added it, by account id */
    public function add(string $value, string $why, ?string $by): string
    {
        $value = strtolower(trim($value));
        $isEmail = filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
        $isDomain = preg_match('/^(?=.{3,253}$)([a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $value) === 1;
        if (!$isEmail && !$isDomain) throw new InvalidArgumentException('Give an email address, such as someone@example.com, or a domain, such as example.com.');
        $this->connection->executeStatement(
            'INSERT INTO cattophp_site_refused_emails (value, why, created_by) VALUES (:value, :why, :by) ON CONFLICT (value) DO UPDATE SET why = EXCLUDED.why',
            ['value' => $value, 'why' => mb_substr(trim($why), 0, 500), 'by' => $by]
        );

        return $value;
    }

    /** Takes one off the list; null when it was not there. */
    public function remove(int $id): ?string
    {
        $value = $this->connection->fetchOne('DELETE FROM cattophp_site_refused_emails WHERE id = :id RETURNING value', ['id' => $id]);

        return $value === false || $value === null ? null : (string) $value;
    }
}
