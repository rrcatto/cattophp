<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel;

use CattoPHP\Security\TwoStep\OneTimeCode;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\RecordingLogger;
use CattoPHP\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/** The whole sign-in, in an application with CattoPHP in it and its records in files, as a page without a database keeps them. */
final class SignInFlowTest extends TestCase
{
    private const BROWSER = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36';

    private string $folder;
    private TestKernel $kernel;
    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->folder = Foundations::folder();
        $this->start();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        Foundations::remove($this->folder);
        restore_exception_handler();
    }

    public function testAPageBehindSignInSendsAStrangerToSignInAndBack(): void
    {
        $this->browser->request('GET', '/settings');

        self::assertSame(303, $this->browser->getResponse()->getStatusCode());
        self::assertSame('/sign-in?return=/settings', $this->browser->getResponse()->headers->get('Location'));
    }

    public function testAPartOfAPageIsToldToGoToSignInAsAWholePage(): void
    {
        $this->browser->request('GET', '/settings', server: ['HTTP_HX_REQUEST' => 'true']);

        self::assertSame(401, $this->browser->getResponse()->getStatusCode());
        self::assertSame('/sign-in', $this->browser->getResponse()->headers->get('HX-Redirect'));
        self::assertSame('', $this->browser->getResponse()->getContent(), 'no sign-in form to swap into the page');
    }

    public function testTheOwnerSignsInWithTheLinkThenSetsUpTheCodeThenUsesIt(): void
    {
        $link = $this->askForALink('owner@example.com', '/settings');
        self::assertStringContainsString('without your one-time code as well', (string) $this->transport()->last()->getTextBody());

        // Opening the link only shows a button: a mail scanner uses nothing up.
        $page = $this->browser->request('GET', $link);
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $this->browser->request('GET', $link);
        $this->browser->submit($page->filter('form')->form());
        self::assertSame('/sign-in/code', $this->browser->getResponse()->headers->get('Location'));

        $page = $this->browser->request('GET', '/sign-in/code');
        self::assertStringContainsString('Set up your one-time code', $page->filter('h1')->text());
        self::assertCount(1, $page->filter('svg[role="img"][aria-label]'), 'the QR code is drawn here');
        $secret = str_replace(' ', '', $page->filter('.cl-two-step-key')->text());

        $this->browser->submit($page->filter('form')->form(['code' => '000000']));
        self::assertStringContainsString('That code is not right', $this->browser->followRedirect()->text());

        $page = $this->browser->getCrawler();
        $this->browser->submit($page->filter('form')->form(['code' => OneTimeCode::codeAt($secret, time())]));
        self::assertSame('/sign-in/recovery-codes', $this->browser->getResponse()->headers->get('Location'));
        $codes = $this->browser->followRedirect()->filter('.cl-recovery-codes code')->each(static fn (Crawler $code): string => $code->text());
        self::assertCount(8, $codes);
        $this->browser->request('GET', '/sign-in/recovery-codes');
        self::assertSame('/', $this->browser->getResponse()->headers->get('Location'), 'shown once');

        $this->browser->request('GET', '/settings');
        self::assertSame('The settings', $this->browser->getResponse()->getContent(), 'the owner is an administrator, who may do anything');
        self::assertFileExists($this->folder . '/storage/two-step/' . hash('sha256', 'owner') . '.json');

        // Signing out, and in again: now the code is asked for, and a recovery code does instead.
        $this->signOut();
        $this->browser->request('GET', '/settings');
        self::assertSame(303, $this->browser->getResponse()->getStatusCode());
        $page = $this->openLink($this->askForALink('owner@example.com'));
        self::assertStringContainsString('Your one-time code', $page->filter('h1')->text());
        $this->browser->submit($page->filter('form')->form(['code' => $codes[0]]));
        self::assertSame('/', $this->browser->getResponse()->headers->get('Location'));
        self::assertSame('Signed in as owner@example.com', $this->browser->followRedirect()->filter('p')->text());
    }

    public function testALinkWorksOnce(): void
    {
        $link = $this->askForALink('owner@example.com');
        $page = $this->browser->request('GET', $link);
        $form = $page->filter('form')->form();
        $this->browser->submit($form);
        $this->browser->submit($form);

        self::assertStringContainsString('That link has been used or has run out', (string) $this->browser->getResponse()->getContent());
    }

    public function testAnyOtherAddressSeesTheSamePageAndGetsNothing(): void
    {
        $page = $this->browser->request('GET', '/sign-in');
        $this->browser->submit($page->filter('form')->form(['email' => 'stranger@example.org']), [], ['HTTP_USER_AGENT' => self::BROWSER, 'REMOTE_ADDR' => '203.0.113.9']);

        self::assertSame('/sign-in/sent', $this->browser->getResponse()->headers->get('Location'));
        self::assertSame([], $this->transport()->sent);
    }

    public function testABotIsShownTheUsualPageAndSentNothing(): void
    {
        $page = $this->browser->request('GET', '/sign-in');
        $this->browser->submit($page->filter('form')->form(['email' => 'owner@example.com', 'website' => 'http://spam.example']), [], ['HTTP_USER_AGENT' => self::BROWSER, 'REMOTE_ADDR' => '203.0.113.9']);

        self::assertSame('/sign-in/sent', $this->browser->getResponse()->headers->get('Location'));
        self::assertSame([], $this->transport()->sent);
    }

    public function testAFormWithoutItsTokenIsRefusedPlainly(): void
    {
        $this->browser->request('POST', '/sign-in', ['email' => 'owner@example.com']);

        self::assertStringContainsString('That form had run out', (string) $this->browser->getResponse()->getContent());
        self::assertSame([], $this->transport()->sent);
    }

    public function testWithoutTwoStepALinkIsEnough(): void
    {
        $this->restart(['AUTH_TWO_STEP' => 'CHOICE']);
        $this->openLink($this->askForALink('owner@example.com', '/settings'), false);

        self::assertSame('/settings', $this->browser->getResponse()->headers->get('Location'));
        $this->browser->followRedirect();
        self::assertSame('The settings', $this->browser->getResponse()->getContent());
    }

    public function testASignInLastsAsLongAsTheSettingSays(): void
    {
        $this->restart(['AUTH_TWO_STEP' => 'CHOICE', 'AUTH_SESSION_TTL' => '1s']);
        $this->openLink($this->askForALink('owner@example.com'), false);
        sleep(2);
        $this->browser->request('GET', '/');

        self::assertSame(303, $this->browser->getResponse()->getStatusCode());
        self::assertStringStartsWith('/sign-in', (string) $this->browser->getResponse()->headers->get('Location'));
    }

    public function testEveryAnswerCarriesTheSecurityHeaders(): void
    {
        $this->browser->request('GET', '/sign-in');
        $headers = $this->browser->getResponse()->headers;

        self::assertStringContainsString("default-src 'none'", (string) $headers->get('Content-Security-Policy'));
        self::assertStringContainsString("img-src 'self';", (string) $headers->get('Content-Security-Policy'), 'the project\'s own directive replaces CattoPHP\'s');
        self::assertStringNotContainsString('img_src', (string) $headers->get('Content-Security-Policy'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', (string) $headers->get('X-Request-Reference'));
        self::assertNull($headers->get('X-Robots-Tag'), 'a live site may be listed');
        self::assertStringNotContainsString(' style=', (string) $this->browser->getResponse()->getContent(), 'no inline styles');

        $this->restart(['APP_SITE_MODE' => 'demo']);
        $page = $this->browser->request('GET', '/sign-in');
        self::assertSame('noindex, nofollow', $this->browser->getResponse()->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('Demo mode', $page->filter('.cl-site-demo')->text());
    }

    public function testAFaultShowsPlainWordsAndAReferenceAndKeepsTheReasonForTheLog(): void
    {
        $page = $this->browser->request('GET', '/open/broken');
        $response = $this->browser->getResponse();
        $reference = (string) $response->headers->get('X-Request-Reference');

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Something went wrong', $page->filter('h1')->text());
        self::assertStringContainsString('It is not something you did.', $page->text());
        self::assertStringContainsString('Reference ' . $reference, $page->filter('.cl-error-reference')->text());
        self::assertStringNotContainsString('10.0.0.5', (string) $response->getContent(), 'the reason is not shown');
        $logged = implode("\n", $this->logger()->lines);
        self::assertStringContainsString('Request ' . $reference . ' failed', $logged);
        self::assertStringContainsString('10.0.0.5', $logged);

        $page = $this->browser->request('GET', '/open/nowhere');
        self::assertSame(404, $this->browser->getResponse()->getStatusCode());
        self::assertSame('Not found', $page->filter('h1')->text());
        self::assertStringContainsString('The address may be mistyped', $page->text());
    }

    public function testARefusedFormComesBackAsItWasTyped(): void
    {
        $this->browser->request('POST', '/open/refused', ['email' => 'typed@example.org', '_token' => 'x']);
        $this->browser->followRedirect();
        $kept = $this->browser->getRequest()->getSession()->get('_cattophp_typed_form');

        self::assertSame(['action' => '/open/refused', 'values' => ['email' => 'typed@example.org']], $kept);
    }

    /** Asks for a link as a person's browser would, and returns its path. */
    private function askForALink(string $email, string $return = ''): string
    {
        $page = $this->browser->request('GET', '/sign-in' . ($return === '' ? '' : '?return=' . rawurlencode($return)));
        $this->browser->submit($page->filter('form')->form(['email' => $email]), [], ['HTTP_USER_AGENT' => self::BROWSER, 'REMOTE_ADDR' => '203.0.113.9']);
        self::assertSame('/sign-in/sent', $this->browser->getResponse()->headers->get('Location'));
        self::assertSame(1, preg_match('#https://example\.com(/sign-in/link/[0-9a-f]{64})#', (string) $this->transport()->last()->getTextBody(), $found));

        return $found[1] ?? '';
    }

    private function openLink(string $link, bool $followToCode = true): Crawler
    {
        $page = $this->browser->request('GET', $link);
        $this->browser->submit($page->filter('form')->form());

        return $followToCode ? $this->browser->followRedirect() : $this->browser->getCrawler();
    }

    private function signOut(): void
    {
        $page = $this->browser->request('GET', '/');
        $this->browser->submit($page->filter('form.sign-out')->form());
        self::assertSame('/sign-in', $this->browser->getResponse()->headers->get('Location'));
    }

    private function transport(): RecordingTransport
    {
        $transport = $this->kernel->getContainer()->get(RecordingTransport::class);
        self::assertInstanceOf(RecordingTransport::class, $transport);

        return $transport;
    }

    private function logger(): RecordingLogger
    {
        $logger = $this->kernel->getContainer()->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);

        return $logger;
    }

    /** @param array<string,string> $starting */
    private function start(array $starting = []): void
    {
        $this->kernel = new TestKernel($this->folder, null, $starting);
        $this->kernel->boot();
        $browser = $this->kernel->getContainer()->get('test.client');
        self::assertInstanceOf(KernelBrowser::class, $browser);
        $this->browser = $browser;
        $this->browser->disableReboot();
    }

    /** @param array<string,string> $starting */
    private function restart(array $starting): void
    {
        $this->kernel->shutdown();
        $this->start($starting);
    }
}
