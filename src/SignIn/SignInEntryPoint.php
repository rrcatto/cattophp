<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Where someone who must sign in is sent: the sign-in page, which brings them back to the page they asked for. A part of
 * a page that htmx asks for is answered 401 and told to go there as a whole page (HX-Redirect, which htmx 4 follows
 * whatever the status), so a sign-in form is never swapped into a page. Symfony keeps a 401, where it would turn a
 * 200 from here into a 403.
 */
final class SignInEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $back = $request->isMethod('GET') && $request->getRequestUri() !== '/' && !$request->headers->has('HX-Request') ? SignInLinks::safePath($request->getRequestUri()) : null;
        $signIn = $this->urls->generate('cattophp_sign_in', $back === null ? [] : ['return' => $back]);
        if ($request->headers->has('HX-Request')) {
            return new Response('', Response::HTTP_UNAUTHORIZED, ['HX-Redirect' => $signIn]);
        }

        return new RedirectResponse($signIn, Response::HTTP_SEE_OTHER);
    }
}
