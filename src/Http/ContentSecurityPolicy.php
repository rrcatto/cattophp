<?php

declare(strict_types=1);

namespace CattoPHP\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The Content-Security-Policy of every page. Everything comes from the site's own address; a script runs only from a
 * file there or with the page's nonce (`{{ csp_nonce() }}` in a template); there are no inline styles, no eval and no
 * framing. A project changes one directive in its configuration (`cattophp.security_headers.policy`), and a route
 * that shows HTML a person wrote (an editor, a sent email) sets `_csp: authored` in its defaults, which allows that
 * HTML's inline styles and https pictures. Scripts stay nonce-only even then, so the HTML cannot run code.
 */
final class ContentSecurityPolicy
{
    public const AUTHORED = 'authored';
    private const NONCE = '_cattophp_csp_nonce';

    public const DIRECTIVES = [
        'default-src' => "'none'",
        'script-src' => "'self'",
        'style-src' => "'self'",
        'img-src' => "'self' data:",
        'font-src' => "'self'",
        'connect-src' => "'self'",
        'manifest-src' => "'self'",
        'form-action' => "'self'",
        'base-uri' => "'none'",
        'frame-ancestors' => "'none'",
        'object-src' => "'none'",
    ];

    /** @param array<string,string> $changes directives replaced or added */
    public function __construct(private readonly RequestStack $requests, private readonly array $changes = [])
    {
    }

    /** The current page's nonce, made when a template first asks for it. */
    public function nonce(): string
    {
        $request = $this->requests->getMainRequest();
        if ($request === null) {
            return '';
        }
        $nonce = $request->attributes->get(self::NONCE);
        if (!is_string($nonce)) {
            $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
            $request->attributes->set(self::NONCE, $nonce);
        }

        return $nonce;
    }

    public function header(Request $request): string
    {
        $directives = array_merge(self::DIRECTIVES, $this->changes);
        $nonce = $request->attributes->get(self::NONCE);
        if (is_string($nonce)) {
            $directives['script-src'] .= " 'nonce-" . $nonce . "'";
        }
        if ($request->attributes->get('_csp') === self::AUTHORED) {
            $directives['style-src'] .= " 'unsafe-inline'";
            $directives['img-src'] .= ' https:';
        }
        $policy = implode('; ', array_map(static fn (string $name, string $value): string => trim($name . ' ' . $value), array_keys($directives), $directives));

        return $request->isSecure() ? $policy . '; upgrade-insecure-requests' : $policy;
    }
}
