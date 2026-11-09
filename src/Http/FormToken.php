<?php

declare(strict_types=1);

namespace CattoPHP\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The hidden field that proves a form came from one of this site's own pages, kept in the visitor's session by
 * Symfony's CSRF protection. Every form that changes something carries it:
 *
 *     <input type="hidden" name="_token" value="{{ form_token() }}">
 */
final class FormToken
{
    public const FIELD = '_token';
    public const ID = 'cattophp';

    public function __construct(private readonly CsrfTokenManagerInterface $tokens)
    {
    }

    public function value(): string
    {
        return $this->tokens->getToken(self::ID)->getValue();
    }

    public function isValid(Request $request): bool
    {
        $sent = $request->request->get(self::FIELD);

        return is_string($sent) && $sent !== '' && $this->tokens->isTokenValid(new CsrfToken(self::ID, $sent));
    }

    /** A new value after sign-in or sign-out, so a token known before is worthless after. */
    public function renew(): void
    {
        $this->tokens->refreshToken(self::ID);
    }
}
