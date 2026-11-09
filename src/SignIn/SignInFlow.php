<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

use CattoPHP\Security\TwoStep\OneTimeCode;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Where a sign-in has got to, in the visitor's session: the link opened (for ten minutes), the key being set up, the
 * wrong codes so far, and when the sign-in finished, which decides how long it lasts.
 */
final class SignInFlow
{
    public const SIGNED_IN_AT = '_cattophp_signed_in_at';
    private const ACCOUNT = '_cattophp_sign_in_account';
    private const OPENED_AT = '_cattophp_sign_in_opened_at';
    private const RETURN = '_cattophp_sign_in_return';
    private const SECRET = '_cattophp_sign_in_secret';
    private const WRONG = '_cattophp_sign_in_wrong';
    private const RECOVERY = '_cattophp_sign_in_recovery';
    private const NOTE = '_cattophp_sign_in_note';
    private const LINK_GOOD_FOR = 600;
    private const WRONG_CODES_ALLOWED = 5;

    public function linkOpened(SessionInterface $session, string $accountId, ?string $returnPath, int $now): void
    {
        $session->migrate(true);
        $session->set(self::ACCOUNT, $accountId);
        $session->set(self::OPENED_AT, $now);
        $session->set(self::RETURN, $returnPath);
        $session->set(self::WRONG, 0);
        $session->remove(self::SECRET);
    }

    /** The account whose link was opened in this session, while it is fresh. */
    public function pending(SessionInterface $session, int $now): ?string
    {
        $account = $session->get(self::ACCOUNT);
        $at = $session->get(self::OPENED_AT);

        return is_string($account) && is_int($at) && $at > $now - self::LINK_GOOD_FOR ? $account : null;
    }

    public function returnPath(SessionInterface $session): ?string
    {
        $path = $session->get(self::RETURN);

        return is_string($path) ? SignInLinks::safePath($path) : null;
    }

    /** The key being set up, kept in the session until a right code proves the phone holds it. */
    public function pendingSecret(SessionInterface $session): string
    {
        $secret = $session->get(self::SECRET);
        if (!is_string($secret) || $secret === '') {
            $secret = OneTimeCode::newSecret();
            $session->set(self::SECRET, $secret);
        }

        return $secret;
    }

    /** One more wrong code. True when that was too many, and the sign-in starts again. */
    public function wrongCode(SessionInterface $session): bool
    {
        $wrong = (int) $session->get(self::WRONG, 0) + 1;
        $session->set(self::WRONG, $wrong);
        if ($wrong < self::WRONG_CODES_ALLOWED) {
            return false;
        }
        $this->clear($session);

        return true;
    }

    public function clear(SessionInterface $session): void
    {
        foreach ([self::ACCOUNT, self::OPENED_AT, self::RETURN, self::SECRET, self::WRONG] as $key) {
            $session->remove($key);
        }
    }

    public function signedIn(SessionInterface $session, int $now): void
    {
        $this->clear($session);
        $session->set(self::SIGNED_IN_AT, $now);
    }

    /** @param list<string> $codes */
    public function showRecoveryCodes(SessionInterface $session, array $codes): void
    {
        $session->set(self::RECOVERY, $codes);
    }

    /** @return list<string> the recovery codes to show, once */
    public function takeRecoveryCodes(SessionInterface $session): array
    {
        $codes = $session->remove(self::RECOVERY);

        return is_array($codes) ? array_values(array_filter($codes, 'is_string')) : [];
    }

    public function note(SessionInterface $session, string $note): void
    {
        $session->set(self::NOTE, $note);
    }

    public function takeNote(SessionInterface $session): string
    {
        $note = $session->remove(self::NOTE);

        return is_string($note) ? $note : '';
    }
}
