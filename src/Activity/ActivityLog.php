<?php

declare(strict_types=1);

namespace CattoPHP\Activity;

use DateTimeImmutable;
use CattoPHP\Http\RequestReference;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The activity log: every change, who made it, from which address and when, with the request's reference. A secret is
 * never written into it: a detail whose name says it is a password, key, token or other secret is kept as ••••, and
 * the password in a mail transport's address too.
 *
 * An action is named `area.what_happened` in small letters, such as `settings.changed` or `sign_in.signed_in`.
 */
final class ActivityLog
{
    public const MASK = '••••';

    /** Detail names whose values are never kept. */
    private const SECRET_NAMES = '/password|passphrase|secret|token|api_?key|private_?key|signing_?key|^dsn$|_dsn$|cookie|authori[sz]ation|one_time_code|recovery_code/i';

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

    /** @return list<ActivityEntry> */
    public function recent(int $limit = 50, ?string $action = null, ?string $subjectType = null, ?string $subjectId = null, ?string $actorId = null): array
    {
        return $this->store->recent($limit, $action, $subjectType, $subjectId, $actorId);
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
        $clean = [];
        foreach ($details as $name => $value) {
            // Nothing, and a yes or no (such as secret_changed), cannot give a secret away.
            if (is_string($name) && preg_match(self::SECRET_NAMES, $name) === 1 && $value !== null && $value !== '' && !is_bool($value)) {
                $clean[$name] = self::MASK;
            } elseif (is_array($value)) {
                $clean[$name] = self::clean($value);
            } elseif (is_string($value)) {
                // A transport's address can carry a password: smtp://user:password@host.
                $clean[$name] = (string) preg_replace('#^([a-z][a-z0-9+.-]*://[^:/@\s]*):[^@/\s]+@#i', '$1:' . self::MASK . '@', $value);
            } else {
                $clean[$name] = $value;
            }
        }

        return $clean;
    }
}
