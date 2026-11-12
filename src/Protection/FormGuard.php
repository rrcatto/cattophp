<?php

declare(strict_types=1);

namespace CattoPHP\Protection;

use CattoPHP\Http\BotCheck;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Guards a public form (sign in, register, contact): refuses one a bot sent (BotCheck's signals) or one naming an email
 * address or domain on the refused list, and leaves what it found on the request for the Site Log. The controller then
 * gives the usual answer and does nothing, so a bot learns nothing. A private address, no address and an address on the
 * Never lock out list are never refused; a form the project names as list-only (Register, which has its own sign-up
 * check) is refused only by the list.
 */
class FormGuard
{
    public const ATTRIBUTE = '_cattophp_protection_form';
    public const TRAP_FIELD = BotCheck::TRAP_FIELD;

    public const SIGN_IN = 'sign_in';
    public const REGISTER = 'register';
    public const CONTACT = 'contact';

    /** Each signal's reason, as the refused forms list says them. */
    public const SIGNALS = BotCheck::SIGNALS + ['email_list' => 'An email address or domain you refuse'];

    /**
     * @param string $hashKey keys the hash of each email address, to count how many one visitor tries without keeping real people's addresses
     * @param array<string,string> $forms each guarded form's name and its words
     * @param list<string> $listOnly the forms only the refused list refuses
     */
    public function __construct(
        private readonly RequestStack $requests,
        private readonly RefusedEmails $refusedEmails,
        private readonly ProtectionSettings $settings,
        private readonly string $hashKey,
        private readonly array $forms = [self::SIGN_IN => 'Sign in', self::REGISTER => 'Register', self::CONTACT => 'Contact'],
        private readonly array $listOnly = [self::REGISTER],
    ) {
    }

    /** @return array<string,string> each guarded form's name and its words */
    public function forms(): array
    {
        return $this->forms;
    }

    /**
     * Whether this form is refused. What was found stays on the request for the Site Log.
     *
     * @param array<string,mixed> $form what the form sent
     */
    public function refuses(string $name, array $form, string $email): bool
    {
        $email = strtolower(trim($email));
        $request = $this->requests->getCurrentRequest();
        $signal = trim((string) (is_scalar($form[self::TRAP_FIELD] ?? null) ? $form[self::TRAP_FIELD] : '')) !== '' ? 'trap' : ($request !== null ? BotCheck::signal($request) : null);
        $listed = $email !== '' && $this->refusedEmails->refuses($email);
        if ($listed) $signal = 'email_list';
        $address = (string) $request?->getClientIp();
        // No address is no web visitor; a private one is a local test site, or a proxy not passing the address on.
        $own = $address === '' || Addresses::isPrivate($address) || $this->settings->isNeverLockedOut($address);
        $refused = !$own && $signal !== null && (!in_array($name, $this->listOnly, true) || $listed);
        $request?->attributes->set(self::ATTRIBUTE, [
            'form' => $name, 'signal' => $own ? null : $signal, 'refused' => $refused, 'email' => $email !== '' ? mb_substr($email, 0, 254) : null,
            'email_hash' => $email !== '' ? hash_hmac('sha256', $email, $this->hashKey) : null,
        ]);

        return $refused;
    }
}
