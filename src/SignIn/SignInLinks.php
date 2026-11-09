<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Http\Throttle;
use CattoPHP\Installation\Installation;
use CattoPHP\Links\LinkStore;
use CattoPHP\Mail\Mailer;
use CattoPHP\Settings\Settings;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The emailed sign-in link: one use, a short life (AUTH_MAGIC_LINK_TTL), kept only as a hash. Asking for one says the
 * same thing whatever happens, so nobody learns which addresses have accounts: an address that may sign in gets an
 * email, any other gets nothing. Each address and each network address may ask only so often
 * (AUTH_REQUEST_LIMIT_EMAIL, AUTH_REQUEST_LIMIT_IP, in AUTH_REQUEST_WINDOW), counted for addresses with and without
 * accounts alike. Opening the link only shows a button: a mail scanner that opens it uses nothing up.
 */
final class SignInLinks
{
    public const SENT = 'sent';
    public const NO_ACCOUNT = 'no_account';
    public const NOT_ALLOWED = 'not_allowed';
    public const TOO_MANY = 'too_many';

    /** A sign-in link's purpose among the links that work once. */
    public const PURPOSE = 'sign_in';

    /** How long a used or run-out link is kept, so a project can still count requests by it. */
    private const KEPT_FOR = '-7 days';

    public function __construct(
        private readonly LinkStore $store,
        private readonly SignInAccounts $accounts,
        private readonly SignInRules $rules,
        private readonly Settings $settings,
        private readonly Throttle $throttle,
        private readonly Mailer $mailer,
        private readonly Installation $installation,
        private readonly UrlGeneratorInterface $urls,
        private readonly ActivityLog $activity,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Sends a link if the address may have one. What happened is for the log and the tests: a page never says.
     *
     * @throws \CattoPHP\Mail\MailNotSent when the email could not be sent
     */
    public function request(string $email, string $networkAddress, ?string $returnPath = null): string
    {
        $email = mb_strtolower(trim($email));
        $window = $this->settings->duration('AUTH_REQUEST_WINDOW');
        if (!$this->throttle->allow('sign_in_email', [$email], $this->settings->int('AUTH_REQUEST_LIMIT_EMAIL'), $window)
            || !$this->throttle->allow('sign_in_network', [$networkAddress], $this->settings->int('AUTH_REQUEST_LIMIT_IP'), $window)) {
            return self::TOO_MANY;
        }
        $account = filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $this->accounts->forEmail($email);
        if ($account === null) {
            return self::NO_ACCOUNT;
        }
        if (!$this->rules->maySignIn($account)) {
            $this->activity->record('sign_in.link_refused', ['reason' => 'not allowed to sign in'], 'account', $account->id);

            return self::NOT_ALLOWED;
        }
        $token = $this->create($account, $returnPath);
        $this->mailer->sendTemplate($account->email, '@CattoPHP/email/sign_in_link.txt.twig', [
            'name' => $this->installation->name(),
            'link' => $this->address($token),
            'lifetime' => $this->settings->duration('AUTH_MAGIC_LINK_TTL')->words(),
            'two_step' => $this->rules->mustUseTwoStep($account),
        ]);
        $this->activity->record('sign_in.link_sent', [], 'account', $account->id);

        return self::SENT;
    }

    /** A new link for an account, for the email or for a command its owner runs when email is not arriving. */
    public function create(SignInAccount $account, ?string $returnPath = null): string
    {
        $now = $this->clock->now();
        $token = bin2hex(random_bytes(32));
        $path = self::safePath($returnPath);
        $this->store->add(self::hash($token), self::PURPOSE, $account->email, $account->id, $path === null ? [] : ['return_path' => $path], null, $now,
            $this->settings->duration('AUTH_MAGIC_LINK_TTL')->after($now));
        $this->store->forgetOld($now->modify(self::KEPT_FOR));

        return $token;
    }

    /** The link's full address. */
    public function address(string $token): string
    {
        return $this->installation->addressOf($this->urls->generate('cattophp_sign_in_link', ['token' => $token]));
    }

    public function usable(string $token): ?SignInLink
    {
        $link = self::isWellFormed($token) ? $this->store->usable(self::hash($token), self::PURPOSE, $this->clock->now()) : null;

        return $link === null ? null : self::signInLink($link);
    }

    /** Uses the link up and returns it; of two attempts at the same moment only one gets it. */
    public function claim(string $token): ?SignInLink
    {
        $now = $this->clock->now();
        $link = self::isWellFormed($token) ? $this->store->usable(self::hash($token), self::PURPOSE, $now) : null;

        return $link !== null && $link->accountId !== null && $this->store->use($link->id, $now) ? self::signInLink($link) : null;
    }

    private static function signInLink(\CattoPHP\Links\Link $link): ?SignInLink
    {
        $path = $link->context['return_path'] ?? null;

        return $link->accountId === null ? null : new SignInLink($link->accountId, $link->expiresAt, is_string($path) ? $path : null);
    }

    public static function isWellFormed(string $token): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $token) === 1;
    }

    /** A path on this site to go to after signing in, or null: never another site's address. */
    public static function safePath(?string $path): ?string
    {
        if ($path === null || $path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\x00-\x1f]/', $path) === 1) {
            return null;
        }

        return mb_substr($path, 0, 2000);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
