<?php

declare(strict_types=1);

namespace CattoPHP\Account;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use CattoPHP\Foundation\Duration;
use CattoPHP\Http\StockPages;
use CattoPHP\Http\Throttle;
use CattoPHP\Http\VisitorAgent;
use CattoPHP\Installation\Installation;
use CattoPHP\Security\TwoStep\OneTimeCode;
use CattoPHP\Security\TwoStep\QrPicture;
use CattoPHP\Security\TwoStep\TwoStep;
use CattoPHP\SignIn\SignInAccount;
use CattoPHP\SignIn\SignInAccounts;
use CattoPHP\SignIn\SignInFlow;
use CattoPHP\SignIn\SignInRules;
use CattoPHP\SignIn\SignInSessions;
use CattoPHP\SignIn\SignInUser;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * A person's Sign-in and security page (`config/routes/account.php`), for someone signed in through CattoPHP's sign-in:
 * two-step sign-in by their own choice, wherever Settings > Sign-in does not require it of them (AUTH_TWO_STEP CHOICE, or
 * ADMINS for anyone but an administrator): set it up with the QR code, turn it off with a current code or a recovery code,
 * and make new recovery codes with a current code. Each wrong code there is in the activity log (two_step.wrong_code),
 * and after WRONG_CODES_ALLOWED of an account's in WRONG_CODES_WINDOW that sign-in is ended and the sign-in page says why,
 * so nobody at a browser left signed in can guess codes without end. Where the project keeps sign-ins a person can see
 * and end (`sign_in.sessions`), where they are signed in, each with End, and End all others. The pages are
 * `@CattoPHP/account/*.html.twig`, in the project's look.
 */
final class SecurityController
{
    /** The key being set up, kept in the session until a right code proves the phone holds it. */
    private const SECRET = '_cattophp_account_two_step_secret';

    /** New recovery codes, kept in the session until the page that shows them once. */
    private const CODES = '_cattophp_account_recovery_codes';

    /** The code fields, never kept to be shown again. */
    private const CODE_FIELDS = ['code'];

    /** How many wrong codes an account may type here in WRONG_CODES_WINDOW: the last of them ends that sign-in. */
    public const WRONG_CODES_ALLOWED = 5;

    /** The time the wrong codes are counted over, as a length of time (Foundation\Duration). */
    public const WRONG_CODES_WINDOW = '15m';

    /** What the sign-in page says to someone whose sign-in was ended for too many wrong codes. */
    public const SIGNED_OUT = 'Too many wrong codes were typed on Sign-in and security, so you were signed out. Sign in again to carry on.';

    public function __construct(
        private readonly StockPages $pages,
        private readonly SignInAccounts $accounts,
        private readonly SignInRules $rules,
        private readonly TwoStep $twoStep,
        private readonly Installation $installation,
        private readonly ClockInterface $clock,
        private readonly Throttle $throttle,
        private readonly SignInFlow $flow,
        private readonly ActivityLog $activity,
        private readonly Security $security,
        private readonly ?SignInSessions $sessions = null,
    ) {
    }

    public function show(Request $request): Response
    {
        $account = $this->account();
        $on = $this->twoStep->isSetUp($account->id);
        $since = $this->twoStep->setUpAt($account->id);
        $required = $this->rules->mustUseTwoStep($account);
        $offers = $this->twoStep->offersRecoveryCodes();

        return $this->pages->page($request, 'account-security', '@CattoPHP/account/security.html.twig', [
            'title' => 'Sign-in and security',
            'lead' => 'How you sign in here, and where you are signed in.',
            'two_step' => [
                'on' => $on,
                'state' => $on ? 'On' . ($since === null ? '' : ' since ' . $this->installation->localTime($since)->format('j F Y')) : 'Off',
                'required' => $required,
                'why' => $required
                    ? ($on ? 'This site requires it of you, so it stays on.' : 'This site requires it of you: you set it up at your next sign-in.')
                    : ($on ? 'You chose it. Signing in asks for a code from your phone after the emailed link.' : 'Signing in needs only the emailed link. Set it up to be asked for a code from your phone as well.'),
                'recovery_codes' => $offers,
                'codes_left' => $on && $offers ? $this->twoStep->recoveryCodesLeft($account->id) : 0,
            ],
            'keeps_sign_ins' => $this->sessions !== null,
            'sign_ins' => $this->signIns($request, $account),
        ]);
    }

    public function setUpPage(Request $request): Response
    {
        $account = $this->account();
        if ($this->twoStep->isSetUp($account->id)) {
            $this->pages->say($request, 'cattophp_info', 'Two-step sign-in is already on.');

            return $this->pages->onTo('cattophp_account_security');
        }
        $session = $request->getSession();
        $secret = $session->get(self::SECRET);
        if (!is_string($secret) || $secret === '') {
            $secret = OneTimeCode::newSecret();
            $session->set(self::SECRET, $secret);
        }

        return $this->pages->page($request, 'account-two-step', '@CattoPHP/account/two-step.html.twig', [
            'title' => 'Set up two-step sign-in',
            'lead' => 'From now on, signing in asks for a code from your phone as well as the emailed link.',
            'qr_code' => QrPicture::svg(OneTimeCode::appAddress($secret, $this->installation->name(), $account->email)),
            'secret' => OneTimeCode::grouped($secret),
        ]);
    }

    public function setUp(Request $request): RedirectResponse
    {
        $account = $this->account();
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_account_two_step', secret: self::CODE_FIELDS);
        }
        $session = $request->getSession();
        if ($this->twoStep->isSetUp($account->id)) {
            // A key left in the session never replaces the one there is: that would need no current code at all.
            $session->remove(self::SECRET);
            $this->pages->say($request, 'cattophp_info', 'Two-step sign-in is already on.');

            return $this->pages->onTo('cattophp_account_security', fragment: 'two-step');
        }
        $secret = $session->get(self::SECRET);
        if (!is_string($secret) || $secret === '') {
            return $this->pages->refused($request, 'That page had run out. Scan the new QR code and type the code again.', 'cattophp_account_two_step', secret: self::CODE_FIELDS);
        }
        $codes = $this->twoStep->setUp($account->id, $secret, trim((string) $request->request->get('code', '')), $this->actor($account));
        if ($codes === null) {
            return $this->pages->refused($request, 'That code is not right, so two-step sign-in is still off. Type the 6 digits your app shows now.', 'cattophp_account_two_step', secret: self::CODE_FIELDS);
        }
        $session->remove(self::SECRET);
        if ($codes === []) {
            return $this->pages->done($request, 'Two-step sign-in is on. Signing in asks for a code from your phone from now on.', 'cattophp_account_security');
        }
        $session->set(self::CODES, $codes);

        return $this->pages->onTo('cattophp_account_recovery_codes');
    }

    public function recoveryCodes(Request $request): Response
    {
        $this->account();
        $codes = $request->getSession()->remove(self::CODES);
        if (!is_array($codes) || $codes === []) {
            return $this->pages->onTo('cattophp_account_security');
        }

        return $this->pages->page($request, 'account-recovery-codes', '@CattoPHP/account/recovery-codes.html.twig', [
            'title' => 'Your recovery codes',
            'lead' => 'Two-step sign-in is on.',
            'codes' => array_values(array_filter($codes, 'is_string')),
        ]);
    }

    public function renewRecoveryCodes(Request $request): RedirectResponse
    {
        $account = $this->account();
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_account_security', fragment: 'two-step', secret: self::CODE_FIELDS);
        }
        if (!$this->twoStep->offersRecoveryCodes() || !$this->twoStep->isSetUp($account->id)) {
            return $this->pages->refused($request, 'Set up two-step sign-in first: recovery codes go with it.', 'cattophp_account_security', fragment: 'two-step', secret: self::CODE_FIELDS);
        }
        // A current code first, so nobody at a computer left signed in can take the codes away.
        if ($this->twoStep->confirm($account->id, trim((string) $request->request->get('code', '')), $this->actor($account)) === null) {
            return $this->wrongCode($request, $account, 'new recovery codes')
                ?? $this->pages->refused($request, 'That code is not right, so your recovery codes are as they were. Type the 6 digits your app shows now, or one of your recovery codes.', 'cattophp_account_security', fragment: 'two-step', secret: self::CODE_FIELDS);
        }
        $request->getSession()->set(self::CODES, $this->twoStep->renewRecoveryCodes($account->id, $this->actor($account)));

        return $this->pages->onTo('cattophp_account_recovery_codes');
    }

    public function turnOff(Request $request): RedirectResponse
    {
        $account = $this->account();
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_account_security', fragment: 'two-step', secret: self::CODE_FIELDS);
        }
        if ($this->rules->mustUseTwoStep($account)) {
            return $this->pages->refused($request, 'This site requires two-step sign-in of you, so it stays on.', 'cattophp_account_security', fragment: 'two-step', secret: self::CODE_FIELDS);
        }
        if (!$this->twoStep->isSetUp($account->id)) {
            return $this->pages->onTo('cattophp_account_security', fragment: 'two-step');
        }
        if ($this->twoStep->confirm($account->id, trim((string) $request->request->get('code', '')), $this->actor($account)) === null) {
            return $this->wrongCode($request, $account, 'turning two-step sign-in off')
                ?? $this->pages->refused($request, 'That code is not right, so two-step sign-in is still on. Type the 6 digits your app shows now, or one of your recovery codes.', 'cattophp_account_security', fragment: 'two-step', secret: self::CODE_FIELDS);
        }
        $this->twoStep->reset($account->id, $this->actor($account));

        return $this->pages->done($request, 'Two-step sign-in is off. Signing in needs only the emailed link now.', 'cattophp_account_security');
    }

    public function endSignIn(Request $request): RedirectResponse
    {
        $account = $this->account();
        $sessions = $this->sessions ?? throw new AccessDeniedException('This site does not keep sign-ins to end.');
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_account_security', fragment: 'sign-ins');
        }
        $id = (string) $request->request->get('sign_in', '');
        if ($id === $sessions->current($request->getSession())?->publicId) {
            return $this->pages->refused($request, 'That is the sign-in you are using now. To end it, sign out.', 'cattophp_account_security', fragment: 'sign-ins');
        }
        if (!$sessions->end($account->id, $id, $this->actor($account))) {
            return $this->pages->refused($request, 'That sign-in had already ended.', 'cattophp_account_security', fragment: 'sign-ins');
        }

        return $this->pages->done($request, 'That sign-in is ended: the browser it was in is signed out.', 'cattophp_account_security', fragment: 'sign-ins');
    }

    public function endOtherSignIns(Request $request): RedirectResponse
    {
        $account = $this->account();
        $sessions = $this->sessions ?? throw new AccessDeniedException('This site does not keep sign-ins to end.');
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_account_security', fragment: 'sign-ins');
        }
        $ended = $sessions->endOthers($account->id, $request->getSession(), $this->actor($account));

        return $this->pages->done($request, match ($ended) {
            0 => 'You were signed in nowhere else.',
            1 => '1 other sign-in is ended. You are signed in only here now.',
            default => $ended . ' other sign-ins are ended. You are signed in only here now.',
        }, 'cattophp_account_security', fragment: 'sign-ins');
    }

    /**
     * A wrong code typed here: in the activity log, and counted for the account (Http\Throttle, so every browser signed in as
     * it counts together). Null while there are tries left; once WRONG_CODES_ALLOWED have been typed in WRONG_CODES_WINDOW,
     * this sign-in is ended (as signing out ends it) and the person is sent to the sign-in page, which says why.
     */
    private function wrongCode(Request $request, SignInAccount $account, string $for): ?RedirectResponse
    {
        $tooMany = !$this->throttle->allow('two_step_wrong_code', [$account->id], self::WRONG_CODES_ALLOWED - 1, Duration::parse(self::WRONG_CODES_WINDOW));
        $this->activity->record('two_step.wrong_code', ['for' => $for] + ($tooMany ? ['signed_out' => true] : []), 'account', $account->id, $this->actor($account));
        if (!$tooMany) {
            return null;
        }
        $session = $request->getSession();
        $this->sessions?->finish($session);
        $this->security->logout(false);
        $session->invalidate();
        $this->flow->note($session, self::SIGNED_OUT);

        return $this->pages->onTo('cattophp_sign_in');
    }

    /** The person signed in, as their account is now. */
    private function account(): SignInAccount
    {
        $user = $this->pages->reader();
        $account = $user instanceof SignInUser ? $this->accounts->forId($user->id) : null;

        return $account ?? throw new AccessDeniedException('This page is for someone signed in.');
    }

    private function actor(SignInAccount $account): Actor
    {
        return new Actor($account->id, $account->name);
    }

    /** @return list<array<string,mixed>> where the person is signed in, this browser first */
    private function signIns(Request $request, SignInAccount $account): array
    {
        if ($this->sessions === null) {
            return [];
        }
        $current = $this->sessions->current($request->getSession())?->publicId;
        $rows = [];
        foreach ($this->sessions->of($account->id) as $signIn) {
            $agent = VisitorAgent::read((string) $signIn->userAgent);
            $rows[] = [
                'id' => $signIn->publicId,
                'browser' => $agent->label . ($agent->device !== '' ? ', a ' . $agent->device : ''),
                'started' => $this->when($signIn->startedAt),
                'last_seen' => $this->when($signIn->lastSeenAt),
                'ends' => $this->when($this->sessions->endsAt($signIn)),
                'current' => $signIn->publicId === $current,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => (int) $b['current'] <=> (int) $a['current']);

        return $rows;
    }

    private function when(\DateTimeImmutable $at): string
    {
        $local = $this->installation->localTime($at);
        $today = $this->installation->localTime($this->clock->now())->format('Y-m-d') === $local->format('Y-m-d');

        return $today ? 'Today at ' . $local->format('H:i') : $local->format('j F Y, H:i');
    }
}
