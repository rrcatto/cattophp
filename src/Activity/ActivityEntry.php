<?php

declare(strict_types=1);

namespace CattoPHP\Activity;

use DateTimeImmutable;

/** One line of the activity log. */
final class ActivityEntry
{
    /** @param array<string,mixed> $details */
    public function __construct(
        public readonly DateTimeImmutable $at,
        public readonly string $action,
        public readonly ?string $actorId,
        public readonly ?string $actorName,
        public readonly ?string $subjectType,
        public readonly ?string $subjectId,
        public readonly array $details,
        public readonly ?string $ipAddress,
        public readonly ?string $requestReference,
    ) {
    }

    /** A detail's key that names an account, whose value is an account's id: account, account_id, to_account, user_id. */
    public const ACCOUNT_KEY = '/(^|_)(account|user)(_id)?$/i';

    /**
     * The entry with an account being deleted no longer named: by $name and nobody's id where it did it, about nobody
     * where it was the subject, and in what it says: a detail (at any depth, in a list or under a key) whose value is the
     * account's id is $name, where its key names an account (ACCOUNT_KEY) or the id is not a bare number, and each of
     * $words (its address, its name, an earlier address, a Telegram username), wherever it stands in a detail's words and
     * whatever its case, is $name too. Such an entry keeps no network address. The entry itself where it names the account
     * nowhere.
     *
     * @param list<string> $words
     */
    public static function forgetting(self $entry, string $accountId, string $name, array $words = []): self
    {
        $actor = $entry->actorId === $accountId;
        $subject = $entry->subjectType === 'account' && $entry->subjectId === $accountId;
        $details = self::detailsForgetting($entry->details, $name, $words, $accountId);
        if (!$actor && !$subject && $details === $entry->details) {
            return $entry;
        }

        return new self($entry->at, $entry->action, $actor ? null : $entry->actorId, $actor ? $name : $entry->actorName, $entry->subjectType,
            $subject ? null : $entry->subjectId, $details, null, $entry->requestReference);
    }

    /**
     * Details with the account no longer named, as forgetting() says: each of $words, whatever its case and wherever it
     * stands in a detail's words (never inside a longer word or address), and the account's id where it is a detail's whole
     * value, as $name.
     *
     * @param array<string,mixed> $details
     * @param list<string> $words
     * @return array<string,mixed>
     */
    public static function detailsForgetting(array $details, string $name, array $words, ?string $accountId = null): array
    {
        $patterns = self::wordPatterns($words);
        $forgotten = self::forgettingIn($details, $name, $patterns, $accountId === null || trim($accountId) === '' ? null : $accountId, false);

        return is_array($forgotten) ? $forgotten : $details;
    }

    /**
     * Each word as a pattern that finds it whatever its case, standing on its own: not inside a longer word or address
     * (anna@example.com is not found in hanna@example.com or anna@example.com.au).
     *
     * @param list<string> $words
     * @return list<string>
     */
    public static function wordPatterns(array $words): array
    {
        $patterns = [];
        foreach (array_unique(array_map(static fn (string $word): string => trim($word), $words)) as $word) {
            if ($word !== '') {
                $patterns[] = '/(?<![\p{L}\p{N}_.%+@-])' . preg_quote($word, '/') . '(?![\p{L}\p{N}_@]|[.-][\p{L}\p{N}])/iu';
            }
        }

        return $patterns;
    }

    /**
     * @param list<string> $patterns
     */
    private static function forgettingIn(mixed $value, string $name, array $patterns, ?string $accountId, bool $accountKey): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::forgettingIn($item, $name, $patterns, $accountId, is_string($key) && preg_match(self::ACCOUNT_KEY, $key) === 1);
            }

            return $value;
        }
        if ($accountId !== null && (is_string($value) || is_int($value)) && (string) $value === $accountId && ($accountKey || !ctype_digit($accountId))) {
            return $name;
        }
        if (is_string($value) && $patterns !== []) {
            $replaced = preg_replace($patterns, $name, $value);

            return is_string($replaced) ? $replaced : $value;
        }

        return $value;
    }
}
