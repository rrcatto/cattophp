<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Http\BotCheck;
use CattoPHP\Http\FormToken;
use CattoPHP\Installation\Installation;
use CattoPHP\Mail\MailNotSent;
use CattoPHP\Security\TwoStep\OneTimeCode;
use CattoPHP\Security\TwoStep\QrPicture;
use CattoPHP\Security\TwoStep\TwoStep;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Signing in: an emailed link, then, where the account needs it, a one-time code (set up with a QR code the first
 * time). Every address sees the same pages, so nobody learns which ones have accounts. The pages are
 * `@CattoPHP/sign_in/*.html.twig`, which a project replaces with its own under `templates/bundles/CattoPHPBundle/`.
 */
final class SignInController
{
    public function __construct(
        private readonly SignInLinks $links,
        private readonly SignInAccounts $accounts,
        private readonly SignInRules $rules,
        private readonly SignInFlow $flow,
        private readonly TwoStep $twoStep,
        private readonly FormToken $tokens,
        private readonly Security $security,
        private readonly Installation $installation,
        private readonly ActivityLog $activity,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        private readonly string $after = '/',
    ) {
    }

    public function start(Request $request): Response
    {
        if ($this->security->getUser() instanceof SignInUser) {
            return $this->redirect($this->after);
        }

        return $this->startPage($request, $this->flow->takeNote($request->getSession()));
    }

    public function send(Request $request): Response
    {
        if (!$this->tokens->isValid($request)) {
            return $this->startPage($request, 'That form had run out. Type your address again.');
        }
        $email = (string) $request->request->get('email', '');
        $signal = BotCheck::signal($request);
        if ($signal !== null) {
            // The usual answer, and nothing sent: the bot learns nothing.
            $this->logger->info('A sign-in form was taken for a bot\'s: {signal}.', ['signal' => BotCheck::SIGNALS[$signal]]);

            return $this->redirect($this->urls->generate('cattophp_sign_in_sent'));
        }
        try {
            $this->links->request($email, (string) $request->getClientIp(), $request->request->get('return') === null ? null : (string) $request->request->get('return'));
        } catch (MailNotSent $failure) {
            // The page says the same whatever happened: what went wrong is in the log.
            $this->logger->error('A sign-in email could not be sent: {message}', ['message' => $failure->getMessage()]);
        }

        return $this->redirect($this->urls->generate('cattophp_sign_in_sent'));
    }

    public function sent(): Response
    {
        return $this->page('@CattoPHP/sign_in/sent.html.twig', ['title' => 'Check your email']);
    }

    public function link(Request $request, string $token): Response
    {
        if ($this->links->usable($token) === null) {
            return $this->startPage($request, 'That link has been used or has run out. Ask for a new one.');
        }

        return $this->page('@CattoPHP/sign_in/link.html.twig', ['title' => 'Sign in', 'link' => $token]);
    }

    public function useLink(Request $request, string $token): Response
    {
        if (!$this->tokens->isValid($request)) {
            return $this->startPage($request, 'That form had run out. Open the link in your email again.');
        }
        $link = $this->links->claim($token);
        $account = $link === null ? null : $this->accounts->forId($link->accountId);
        if ($link === null || $account === null || !$this->rules->maySignIn($account)) {
            return $this->startPage($request, 'That link has been used or has run out. Ask for a new one.');
        }
        $session = $request->getSession();
        if (!$this->rules->mustUseTwoStep($account) && !$this->twoStep->isSetUp($account->id)) {
            return $this->finish($request, $account, $link->returnPath, []);
        }
        $this->flow->linkOpened($session, $account->id, $link->returnPath, $this->now());

        return $this->redirect($this->urls->generate('cattophp_sign_in_code'));
    }

    public function code(Request $request): Response
    {
        $session = $request->getSession();
        $account = $this->pendingAccount($session);
        if ($account === null) {
            return $this->startPage($request, 'Open the link in your email first.');
        }
        $setUp = $this->twoStep->isSetUp($account->id);
        $secret = $setUp ? '' : $this->flow->pendingSecret($session);

        return $this->page('@CattoPHP/sign_in/code.html.twig', [
            'title' => $setUp ? 'Your one-time code' : 'Set up your one-time code',
            'set_up' => $setUp,
            'secret' => $setUp ? '' : OneTimeCode::grouped($secret),
            'qr_code' => $setUp ? '' : QrPicture::svg(OneTimeCode::appAddress($secret, $this->installation->name(), $account->email)),
            'recovery_codes' => $this->twoStep->offersRecoveryCodes(),
            'note' => $this->flow->takeNote($session),
        ]);
    }

    public function checkCode(Request $request): Response
    {
        $session = $request->getSession();
        if (!$this->tokens->isValid($request)) {
            return $this->startPage($request, 'That form had run out. Ask for a new link.');
        }
        $account = $this->pendingAccount($session);
        if ($account === null) {
            return $this->startPage($request, 'Open the link in your email first.');
        }
        $typed = (string) $request->request->get('code', '');
        $codes = [];
        if ($this->twoStep->isSetUp($account->id)) {
            $right = $this->twoStep->check($account->id, $typed);
        } else {
            $made = $this->twoStep->setUp($account->id, $this->flow->pendingSecret($session), $typed);
            $right = $made !== null;
            $codes = $made ?? [];
        }
        if (!$right) {
            $this->activity->record('sign_in.wrong_code', [], 'account', $account->id);
            if ($this->flow->wrongCode($session)) {
                return $this->startPage($request, 'Too many wrong codes. Ask for a new link.');
            }
            $this->flow->note($session, 'That code is not right. Type the 6 digits your app shows now.');

            return $this->redirect($this->urls->generate('cattophp_sign_in_code'));
        }

        return $this->finish($request, $account, $this->flow->returnPath($session), $codes);
    }

    public function recoveryCodes(Request $request): Response
    {
        $codes = $this->flow->takeRecoveryCodes($request->getSession());
        if ($codes === []) {
            return $this->redirect($this->after);
        }

        return $this->page('@CattoPHP/sign_in/recovery_codes.html.twig', ['title' => 'Your recovery codes', 'codes' => $codes, 'continue' => $this->after]);
    }

    public function signOut(Request $request): Response
    {
        $user = $this->security->getUser();
        if ($this->tokens->isValid($request) && $user !== null) {
            $this->activity->record('sign_in.signed_out', [], 'account', $user instanceof SignInUser ? $user->id : $user->getUserIdentifier());
            $this->security->logout(false);
            $request->getSession()->invalidate();
        }

        return $this->redirect($this->urls->generate('cattophp_sign_in'));
    }

    /** @param list<string> $recoveryCodes new codes to show once, after signing in */
    private function finish(Request $request, SignInAccount $account, ?string $returnPath, array $recoveryCodes): Response
    {
        $session = $request->getSession();
        $this->security->login(SignInUser::of($account, $this->rules->isAdministrator($account)), SignInAuthenticator::class);
        $this->flow->signedIn($session, $this->now());
        $this->activity->record('sign_in.signed_in', [], 'account', $account->id);
        if ($recoveryCodes !== []) {
            $this->flow->showRecoveryCodes($session, $recoveryCodes);

            return $this->redirect($this->urls->generate('cattophp_sign_in_recovery_codes'));
        }

        return $this->redirect($returnPath ?? $this->after);
    }

    private function pendingAccount(SessionInterface $session): ?SignInAccount
    {
        $id = $this->flow->pending($session, $this->now());
        $account = $id === null ? null : $this->accounts->forId($id);

        return $account !== null && $this->rules->maySignIn($account) ? $account : null;
    }

    private function startPage(Request $request, string $note): Response
    {
        $return = SignInLinks::safePath((string) ($request->query->get('return') ?? $request->request->get('return') ?? ''));

        return $this->page('@CattoPHP/sign_in/start.html.twig', ['title' => 'Sign in', 'note' => $note, 'return' => $return ?? '']);
    }

    /** @param array<string,mixed> $context */
    private function page(string $template, array $context): Response
    {
        return new Response($this->twig->render($template, $context));
    }

    private function redirect(string $to): RedirectResponse
    {
        return new RedirectResponse($to, Response::HTTP_SEE_OTHER);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
