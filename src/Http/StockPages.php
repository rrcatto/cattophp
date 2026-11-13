<?php

declare(strict_types=1);

namespace CattoPHP\Http;

use CattoPHP\Activity\Actor;
use CattoPHP\Activity\NamedAccount;
use CattoPHP\Theme\PageRenderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;

/**
 * What CattoPHP's own pages (Administration's Settings, Roles and Activity log; a person's Sign-in and security) do the
 * same way: draw a page in the project's look (its theme through the page renderer where it has one, else its layout),
 * with what the reader's last form did said at the top; check a form's token and a permission; and after a form send the
 * reader on to a page, never answering a POST with one, saying what happened, or, when it was refused, keeping the form as
 * it was typed (never a secret) and saying why. What they say waits in Symfony's session under CattoPHP's own names, so a
 * project's own messages never show twice.
 */
final class StockPages
{
    /** Each kind of message, as the notice's tone: what was done, what was refused, what is worth knowing. */
    public const TONES = ['cattophp_done' => 'success', 'cattophp_refused' => 'danger', 'cattophp_info' => 'info'];

    /** Where why each field of a refused form was refused waits for the form. */
    public const ERRORS = 'cattophp_errors';

    /**
     * The names CattoPHP's own pages are drawn as, each with where: a project's PageProject is given the name
     * (family(), prepare() and model()) and puts the page in one of its families.
     */
    public const PAGES = [
        'sign-in' => 'Every step of signing in: /sign-in, /sign-in/sent, /sign-in/link/{token}, /sign-in/code and /sign-in/recovery-codes (SignIn\\SignInController, @CattoPHP/sign_in/)',
        'error' => 'Every error page (Http\\TwigErrorPageDrawer, @CattoPHP/error.html.twig)',
        'admin-settings' => 'Settings, /admin/settings (Admin\\SettingsController, @CattoPHP/admin/settings.html.twig)',
        'admin-roles' => 'Roles, /admin/roles (Admin\\RolesController, @CattoPHP/admin/roles.html.twig)',
        'admin-role-new' => 'A new role, /admin/roles/new (Admin\\RolesController, @CattoPHP/admin/role-new.html.twig)',
        'admin-role' => 'One role, /admin/roles/{key} (Admin\\RolesController, @CattoPHP/admin/role.html.twig)',
        'admin-activity' => 'The activity log, /admin/activity (Admin\\ActivityController, @CattoPHP/admin/activity.html.twig)',
        'account-security' => 'Sign-in and security, /account/security (Account\\SecurityController, @CattoPHP/account/security.html.twig)',
        'account-two-step' => 'Setting two-step sign-in up, /account/security/two-step (Account\\SecurityController, @CattoPHP/account/two-step.html.twig)',
        'account-recovery-codes' => 'New recovery codes, shown once, /account/security/recovery-codes (Account\\SecurityController, @CattoPHP/account/recovery-codes.html.twig)',
    ];

    public function __construct(
        private readonly Environment $twig,
        private readonly FormToken $tokens,
        private readonly UrlGeneratorInterface $urls,
        private readonly ?AuthorizationCheckerInterface $authorization = null,
        private readonly ?TokenStorageInterface $tokenStorage = null,
        private readonly ?PageRenderer $pages = null,
    ) {
    }

    /**
     * A page: the template drawn as the page named $page (its family and the project's model, in the theme), or alone in
     * the project's layout. Its data is words, numbers, true or false and lists of them.
     *
     * @param array<string,mixed> $data
     */
    public function page(Request $request, string $page, string $template, array $data, int $status = Response::HTTP_OK): Response
    {
        $data['cattophp_notices'] = $this->notices($request);

        return new Response($this->pages !== null ? $this->pages->renderTemplate($page, $template, $data) : $this->twig->render($template, $data), $status);
    }

    /** Whether a form came from one of the site's own pages, recently: its token. */
    public function tokenIsValid(Request $request): bool
    {
        return $this->tokens->isValid($request);
    }

    /** Refuses a reader without the permission: a stranger is sent to sign in, anyone else told it is not theirs. */
    public function deny(string $permission): void
    {
        if ($this->authorization === null || !$this->authorization->isGranted($permission)) {
            throw new AccessDeniedException('This page needs the permission ' . $permission . '.');
        }
    }

    public function may(string $permission): bool
    {
        return $this->authorization !== null && $this->authorization->isGranted($permission);
    }

    /** The person signed in, or null. */
    public function reader(): ?UserInterface
    {
        return $this->tokenStorage?->getToken()?->getUser();
    }

    /** The person signed in as the activity log names who did something; the system when nobody is. */
    public function actor(): Actor
    {
        $user = $this->reader();
        if ($user instanceof NamedAccount) {
            return new Actor($user->accountId(), $user->accountName());
        }

        return $user === null ? Actor::system() : new Actor($user->getUserIdentifier(), $user->getUserIdentifier());
    }

    /**
     * On to a page after a form that did what it was sent to do, saying so.
     *
     * @param array<string,mixed> $parameters
     */
    public function done(Request $request, string $message, string $route, array $parameters = [], string $fragment = ''): RedirectResponse
    {
        $this->say($request, 'cattophp_done', $message);

        return $this->onTo($route, $parameters, $fragment);
    }

    /**
     * Back to the form as it was typed, saying why it was refused and what to do. $secret names the form's secret fields
     * beside those its names give away (Http\TypedForm), which are never kept; $errors says why each field was refused,
     * by its name, for the form to show beside it (errors()): a map, or the FieldsRefused a service threw.
     *
     * @param array<string,mixed> $parameters
     * @param list<string> $secret
     * @param array<string,string>|FieldsRefused $errors
     */
    public function refused(Request $request, string $message, string $route, array $parameters = [], string $fragment = '', array $secret = [], array|FieldsRefused $errors = []): RedirectResponse
    {
        if ($errors instanceof FieldsRefused) {
            $errors = $errors->errors();
        }
        TypedForm::keep($request, $secret);
        $this->say($request, 'cattophp_refused', $message);
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($errors !== [] && $session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add(self::ERRORS, $errors);
        }

        return $this->onTo($route, $parameters, $fragment);
    }

    /** @return array<string,string> why each field of the form just refused was refused, by its name, taken now */
    public function errors(Request $request): array
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        $errors = [];
        foreach ($session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag()->get(self::ERRORS) : [] as $refused) {
            if (is_array($refused)) {
                foreach ($refused as $name => $why) {
                    $errors[(string) $name] = is_scalar($why) ? (string) $why : '';
                }
            }
        }

        return $errors;
    }

    /**
     * A form whose token had run out: nothing was done.
     *
     * @param array<string,mixed> $parameters
     * @param list<string> $secret
     */
    public function expired(Request $request, string $route, array $parameters = [], string $fragment = '', array $secret = []): RedirectResponse
    {
        return $this->refused($request, 'That form had run out, so nothing was changed. Try again.', $route, $parameters, $fragment, $secret);
    }

    /**
     * On to a page, saying nothing.
     *
     * @param array<string,mixed> $parameters
     */
    public function onTo(string $route, array $parameters = [], string $fragment = ''): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate($route, $parameters) . ($fragment !== '' ? '#' . $fragment : ''), Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string,mixed> $parameters
     */
    public function path(string $route, array $parameters = []): string
    {
        return $this->urls->generate($route, $parameters);
    }

    /** Leaves a message for the next page the reader sees: one of TONES's kinds. */
    public function say(Request $request, string $kind, string $message): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface && isset(self::TONES[$kind])) {
            $session->getFlashBag()->add($kind, $message);
        }
    }

    /** @return list<array{tone:string,text:string}> the messages waiting for the reader, taken now */
    private function notices(Request $request): array
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return [];
        }
        $notices = [];
        foreach (self::TONES as $kind => $tone) {
            foreach ($session->getFlashBag()->get($kind) as $message) {
                if (is_string($message)) {
                    $notices[] = ['tone' => $tone, 'text' => $message];
                }
            }
        }

        return $notices;
    }
}
