<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel\App;

use CattoPHP\Http\FormToken;
use CattoPHP\Http\TypedForm;
use CattoPHP\SignIn\SignInUser;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** The test application's own pages. */
final class PagesController
{
    public function __construct(private readonly Security $security, private readonly FormToken $tokens, private readonly \Twig\Environment $twig)
    {
    }

    public function home(): Response
    {
        $user = $this->security->getUser();

        return new Response('<!doctype html><html><body><p>Signed in as ' . htmlspecialchars($user instanceof SignInUser ? $user->name : '?') . '</p>'
            . '<form class="sign-out" method="post" action="/sign-out"><input type="hidden" name="_token" value="' . $this->tokens->value() . '"><button>Sign out</button></form></body></html>');
    }

    public function settings(): Response
    {
        if (!$this->security->isGranted('SYSTEM.SETTINGS.MANAGE')) {
            throw new AccessDeniedException();
        }

        return new Response('The settings');
    }

    public function broken(): Response
    {
        throw new RuntimeException('SQLSTATE[08006] the database is down at 10.0.0.5');
    }

    public function refused(Request $request): Response
    {
        TypedForm::keep($request);

        return new RedirectResponse((string) $request->query->get('back', '/open/form'), Response::HTTP_SEE_OTHER);
    }

    /** A page with the form a refusal sends the person back to. */
    public function form(): Response
    {
        return new Response($this->twig->createTemplate('<!doctype html><title>Form</title><p class="typed">{{ typed_for(\'/open/refused\').email ?? \'\' }}</p>')->render());
    }
}
