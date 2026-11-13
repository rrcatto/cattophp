<?php

declare(strict_types=1);

namespace CattoPHP\Activity;

use DateTimeImmutable;
use CattoPHP\Http\RequestReference;
use CattoPHP\Security\Redactor;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The activity log: every change, who made it, from which address and when, with the request's reference. A secret is
 * never written into it (Security\Redactor): a detail whose name says it is a password, key, token or other secret is
 * kept as ••••, and so is a secret written in text, such as the password in a mail transport's address.
 *
 * An action is named `area.what_happened` in small letters, such as `settings.changed` or `sign_in.signed_in`.
 */
final class ActivityLog
{
    public const MASK = Redactor::MASK;

    public function __construct(
        private readonly ActivityStore $store,
        private readonly ClockInterface $clock,
        private readonly ?RequestStack $requests = null,
        private readonly ?TokenStorageInterface $tokens = null,
    ) {
    }

    /**
     * @param array<string,mixed> $details what is worth knowing later, such as a setting's old and new values
     * @param Actor|null $actor who did it; null is the signed-in person, or the system when nobody is signed in
     */
    public function record(string $action, array $details = [], ?string $subjectType = null, ?string $subjectId = null, ?Actor $actor = null): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $action) !== 1) {
            throw new InvalidArgumentException(sprintf('An activity is named area.what_happened in small letters: "%s" is not.', $action));
        }
        $actor ??= $this->signedIn() ?? Actor::system();
        $request = $this->requests?->getMainRequest();
        $this->store->add(new ActivityEntry(
            $this->clock->now(),
            $action,
            $actor->id,
            $actor->name,
            $subjectType,
            $subjectId,
            self::clean($details),
            $request?->getClientIp(),
            $request === null ? null : RequestReference::of($request),
        ));
    }

    /**
     * The newest entries first, narrowed by what is given, from the $offset-th on: a page of the log.
     *
     * @return list<ActivityEntry>
     */
    public function recent(int $limit = 50, ?string $action = null, ?string $subjectType = null, ?string $subjectId = null, ?string $actorId = null, int $offset = 0): array
    {
        return $this->store->recent($limit, $action, $subjectType, $subjectId, $actorId, max(0, $offset));
    }

    /** How many entries there are, narrowed as recent() narrows them. */
    public function count(?string $action = null, ?string $subjectType = null, ?string $subjectId = null, ?string $actorId = null): int
    {
        return $this->store->count($action, $subjectType, $subjectId, $actorId);
    }

    /**
     * Every action the log holds, in alphabetical order: what a page may filter it by.
     *
     * @return list<string>
     */
    public function actions(): array
    {
        return $this->store->actions();
    }

    /** Removes every entry from before a moment, for a log kept only so long; returns how many went. */
    public function removeBefore(DateTimeImmutable $before): int
    {
        return $this->store->removeBefore($before);
    }

    /** The signed-in person, if there is one. */
    public function signedIn(): ?Actor
    {
        $user = $this->tokens?->getToken()?->getUser();
        if ($user === null) {
            return null;
        }
        if ($user instanceof NamedAccount) {
            return new Actor($user->accountId(), $user->accountName());
        }

        return new Actor($user->getUserIdentifier(), $user->getUserIdentifier());
    }

    /**
     * Details with every secret masked, at any depth.
     *
     * @param array<array-key,mixed> $details
     * @return array<array-key,mixed>
     */
    public static function clean(array $details): array
    {
        return Redactor::data($details);
    }
}
