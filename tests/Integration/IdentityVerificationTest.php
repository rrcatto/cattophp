<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Integration;

use CattoPHP\Identity\DiditClient;
use CattoPHP\Identity\DiditSignature;
use CattoPHP\Identity\IdentityAccounts;
use CattoPHP\Identity\IdentityApproved;
use CattoPHP\Identity\IdentityChecks;
use CattoPHP\Identity\IdentitySettings;
use CattoPHP\Identity\IdentityVerification;
use CattoPHP\Identity\InvalidDiditWebhook;
use CattoPHP\Runtime\SiteMode;
use CattoPHP\Settings\EnvironmentValues;
use CattoPHP\Tests\Support\DatabaseTestCase;
use CattoPHP\Tests\Support\Foundations;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Identity verification on Didit's hosted page, with a stand-in for Didit, ported from cattolms's IdentityVerificationIntegrationTest. */
final class IdentityVerificationTest extends DatabaseTestCase
{
    private const SECRET = 'integration-webhook-secret';
    private const KEYS = ['DIDIT_API_KEY' => 'integration-api-key', 'DIDIT_WORKFLOW_ID' => 'workflow-1234', 'DIDIT_WEBHOOK_SECRET' => self::SECRET, 'APP_URL' => 'https://example.com'];

    private Foundations $site;
    private IdentityChecks $checks;
    /** @var list<array{method:string,url:string,body:string,headers:list<string>}> */
    private array $requests = [];
    /** @var list<MockResponse> */
    private array $answers = [];
    /** @var list<string> the accounts IdentityApproved was told of */
    public array $approvals = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = new Foundations();
        $this->checks = new IdentityChecks($this->connection);
    }

    public function testAStartedCheckSendsThePersonToDiditAndTheirReturnRecordsTheDecision(): void
    {
        $session = $this->uuid();
        $this->answers = [
            new MockResponse((string) json_encode(['session_id' => $session, 'url' => 'https://verify.didit.me/session/abc', 'status' => 'Not Started']), ['http_code' => 201]),
            new MockResponse((string) json_encode(self::report('Approved', 'Lerato', 'Dlamini', '1994-02-03') + ['session_id' => $session]), ['http_code' => 200]),
        ];
        $service = $this->service();

        self::assertSame('https://verify.didit.me/session/abc', $service->start('7'));
        $sent = json_decode($this->requests[0]['body'], true);
        self::assertSame(['POST', 'https://verification.didit.me/v3/session/'], [$this->requests[0]['method'], $this->requests[0]['url']]);
        self::assertSame(['workflow-1234', 'REF-7', 'https://example.com/account/verification/return'], [$sent['workflow_id'], $sent['vendor_data'], $sent['callback']], 'The account\'s reference is the vendor_data.');
        self::assertContains('x-api-key: integration-api-key', $this->requests[0]['headers']);
        self::assertSame('started', ($this->checks->latest('7') ?? [])['status']);

        $state = $service->returned('7', $session);
        self::assertSame('https://verification.didit.me/v3/session/' . $session . '/decision/', $this->requests[1]['url'], 'The return reads the decision from Didit.');
        self::assertTrue($state['verified']);
        $row = $this->checks->approved('7') ?? [];
        self::assertSame(['Lerato Dlamini', 'Identity Card', '9005125800087', 'South Africa', '1994-02-03'],
            [$row['verified_name'], $row['document_type'], $row['document_number'], $row['issuing_country'], substr((string) $row['date_of_birth'], 0, 10)]);
        self::assertSame(['7'], $this->approvals, 'The project is told once.');
        $approved = $this->site->activityStore->recent(1, 'identity.verification_approved')[0];
        self::assertSame(['user_id' => 7, 'verification_id' => (int) $row['id'], 'session_id' => $session, 'document_type' => 'Identity Card', 'issuing_country' => 'South Africa'], $approved->details,
            'Never the document number or the date of birth.');
        self::assertNull($approved->actorId, 'Didit\'s decision is the system\'s.');
        self::assertSame('7', $this->site->activityStore->recent(1, 'identity.verification_started')[0]->actorId);
        try {
            $service->start('7');
            self::fail('A verified account is never asked again.');
        } catch (RuntimeException $already) {
            self::assertSame('Your identity is already verified.', $already->getMessage());
        }
        $this->expectExceptionMessage('That identity check could not be found.');
        $service->returned('8', $session);
    }

    public function testTheWebhookIsBelievedOnlyWhenSignedAndEachEventOnce(): void
    {
        $session = $this->uuid();
        $this->checks->create('9', $session);
        $service = $this->service();
        $event = $this->uuid();
        $declined = ['event_id' => $event, 'webhook_type' => 'status.updated', 'timestamp' => time(), 'session_id' => $session, 'status' => 'Declined',
            'vendor_data' => 'x', 'decision' => ['id_verifications' => [['status' => 'Declined', 'warnings' => [['log_type' => 'error', 'short_description' => 'Document expired', 'long_description' => 'The document has expired.']]]]]];

        try {
            $service->webhook((string) json_encode($declined), ['x-timestamp' => (string) time()]);
            self::fail('An unsigned webhook is refused.');
        } catch (InvalidDiditWebhook) {
            self::assertSame('started', ($this->checks->bySession($session) ?? [])['status']);
        }
        $service->webhook(...$this->signed($declined));
        $row = $this->checks->bySession($session) ?? [];
        self::assertSame(['declined', 'The document has expired.'], [$row['status'], $row['decline_reason']]);

        $service->webhook(...$this->signed(['status' => 'Approved', 'decision' => self::report('Approved')] + $declined));
        self::assertSame('declined', ($this->checks->bySession($session) ?? [])['status'], 'Each event is handled once.');

        $service->webhook(...$this->signed(['event_id' => $this->uuid(), 'status' => 'Approved', 'decision' => self::report('Approved', 'Sipho', 'Ndlovu')] + $declined));
        self::assertSame('Sipho Ndlovu', ($this->checks->approved('9') ?? [])['verified_name'], 'A reviewer\'s later decision is a new event, and is applied.');

        $service->webhook(...$this->signed(['event_id' => $this->uuid(), 'session_id' => $this->uuid()] + $declined));
        $service->webhook(...$this->signed(['event_id' => $this->uuid(), 'webhook_type' => 'user.status.updated', 'status' => 'BLOCKED'] + $declined));
        self::assertSame('approved', ($this->checks->bySession($session) ?? [])['status'], 'Another session or another kind of event is ignored.');
        $this->expectException(InvalidDiditWebhook::class);
        $service->webhook('not json', ['x-signature' => hash_hmac('sha256', 'not json', self::SECRET), 'x-timestamp' => (string) time()]);
    }

    public function testThreeDeclinedChecksBlockUntilAnotherTryIsAllowedAndARevokedVerificationMustBeDoneAgain(): void
    {
        $service = $this->service();
        for ($try = 1; $try <= 3; $try++) {
            $session = $this->uuid();
            $this->checks->create('11', $session);
            $service->apply($session, 'Declined', ['id_verifications' => [['warnings' => [['log_type' => 'error', 'short_description' => 'Face does not match']]]]]);
            self::assertSame(3 - $try, $service->state('11')['tries_left']);
        }
        self::assertTrue($service->state('11')['blocked']);
        try {
            $service->start('11');
            self::fail('Three declined checks block a fourth.');
        } catch (RuntimeException $blocked) {
            self::assertSame('Your identity check was declined 3 times. Please contact us so we can allow another try.', $blocked->getMessage());
        }
        $this->connection->executeStatement("UPDATE cattophp_identity_verifications SET started_at = started_at - INTERVAL '1 minute' WHERE account = '11'");
        $service->allowAnotherTry('11', '1');
        self::assertSame([3, false], [$service->state('11')['tries_left'], $service->state('11')['blocked']], 'An administrator allowed another try.');
        self::assertSame(['1', ['user_id' => 11]], [$this->site->activityStore->recent(1, 'identity.retry_allowed')[0]->actorId, $this->site->activityStore->recent(1, 'identity.retry_allowed')[0]->details]);

        $approved = $this->verify('11', 'Thabo', 'Mokoena');
        try {
            $service->revoke($approved, '1', ' ');
            self::fail('A revocation needs a reason.');
        } catch (InvalidArgumentException) {
            self::assertTrue($service->isVerified('11'));
        }
        $service->revoke($approved, '1', 'The document belonged to someone else.');
        self::assertFalse($service->isVerified('11'), 'The account must verify again.');
        $row = $this->checks->find($approved) ?? [];
        self::assertSame(['revoked', 'The document belonged to someone else.', '1'], [$row['status'], $row['revocation_reason'], $row['revoked_by']]);
        self::assertSame(['user_id' => 11, 'verification_id' => $approved, 'reason' => 'The document belonged to someone else.'], $this->site->activityStore->recent(1, 'identity.verification_revoked')[0]->details);
        self::assertSame(['revoked', 'declined', 'declined', 'declined'], array_column($service->history('11'), 'status'));
        self::assertSame('Revoked', $service->history('11')[0]['status_words']);
        try {
            $service->revoke($approved, '1', 'Again.');
            self::fail('Only an approved verification can be revoked.');
        } catch (InvalidArgumentException $only) {
            self::assertSame('Only an approved verification can be revoked.', $only->getMessage());
        }
        $service->apply((string) $row['session_id'], 'Approved', self::report('Approved'));
        self::assertSame('revoked', ($this->checks->find($approved) ?? [])['status'], 'A revoked check is never changed.');
    }

    public function testSomeoneYoungerThanTheMinimumAgeIsVerifiedButNotPassedOn(): void
    {
        $service = $this->service();
        $this->verify('12', 'Young', 'Learner', (new \DateTimeImmutable('-16 years'))->format('Y-m-d'));
        self::assertTrue($service->state('12')['minor']);
        self::assertTrue($service->state('12')['verified']);
        self::assertSame([], $this->approvals, 'Nothing is passed on for someone under the minimum age.');
        $this->verify('13', 'Grown', 'Learner');
        self::assertSame([false, ['13']], [$service->state('13')['minor'], $this->approvals]);
    }

    public function testAnApprovalWithoutANameWaitsAndASecondApprovalIsNotTaken(): void
    {
        $service = $this->service();
        $nameless = $this->uuid();
        $this->checks->create('14', $nameless);
        $service->apply($nameless, 'Approved', ['id_verifications' => [['document_type' => 'Passport']]]);
        self::assertSame('started', ($this->checks->bySession($nameless) ?? [])['status'], 'A check approved without a name waits for a reviewer.');
        $service->apply($nameless, 'In Review', []);
        self::assertSame(['in_review', true], [($this->checks->bySession($nameless) ?? [])['status'], $service->state('14')['in_review']]);
        self::assertSame([['account' => '14', 'session_id' => $nameless]], array_map(static fn(array $row): array => array_intersect_key($row, ['account' => 1, 'session_id' => 1]), $service->inReview()));
        try {
            $service->start('14');
            self::fail('No second check while one is reviewed.');
        } catch (RuntimeException $review) {
            self::assertSame('Your identity check is being reviewed. We will let you know the result.', $review->getMessage());
        }
        $first = $this->verify('15', 'One', 'Person');
        $second = $this->uuid();
        $this->checks->create('15', $second);
        $service->apply($second, 'Approved', self::report('Approved', 'Other', 'Person'));
        self::assertSame([$first, 'started'], [(int) ($this->checks->approved('15') ?? [])['id'], ($this->checks->bySession($second) ?? [])['status']], 'One verification at a time.');
        $service->apply($second, 'In Progress', []);
        self::assertSame('started', ($this->checks->bySession($second) ?? [])['status']);
    }

    /** Didit's sandbox mocks its checks and can force a pass, so a live site never takes its result; a demo site does. */
    public function testALiveSiteIgnoresASandboxResultAndADemoSiteTakesIt(): void
    {
        $live = SiteMode::fixed(SiteMode::LIVE);
        $session = $this->uuid();
        $this->checks->create('16', $session);
        $approved = ['event_id' => $this->uuid(), 'webhook_type' => 'status.updated', 'session_id' => $session, 'status' => 'Approved',
            'vendor_data' => 'x', 'environment' => 'sandbox', 'decision' => self::report('Approved', 'Thabo', 'Mokoena')];

        $this->service($live)->webhook(...$this->signed($approved));
        self::assertSame('started', ($this->checks->bySession($session) ?? [])['status'], 'A sandbox webhook verifies nobody on a live site.');
        self::assertSame(['user_id' => 16, 'session_id' => $session], $this->site->activityStore->recent(1, 'identity.sandbox_ignored')[0]->details, 'It is recorded.');

        $returning = $this->uuid();
        $this->checks->create('17', $returning);
        $this->answers = [new MockResponse((string) json_encode(self::report('Approved', 'Naledi', 'Khumalo') + ['session_id' => $returning, 'environment' => 'sandbox']), ['http_code' => 200])];
        self::assertFalse($this->service($live)->returned('17', $returning)['verified'], 'Nor does a sandbox decision read on the return.');

        $this->service($live)->webhook(...$this->signed(['event_id' => $this->uuid(), 'environment' => 'live'] + $approved));
        self::assertSame('Thabo Mokoena', ($this->checks->approved('16') ?? [])['verified_name'], 'A live result is taken on a live site.');

        $this->answers = [new MockResponse((string) json_encode(self::report('Approved', 'Naledi', 'Khumalo') + ['session_id' => $returning, 'environment' => 'sandbox']), ['http_code' => 200])];
        self::assertTrue($this->service(SiteMode::fixed(SiteMode::DEMO))->returned('17', $returning)['verified'], 'A demo site tests on the sandbox.');
        $this->answers = [new MockResponse('{}', ['http_code' => 503])];
        self::assertTrue($this->service()->returned('17', $returning)['verified'], 'Didit out of reach: the check as it stands.');
    }

    /** Didit's keys say what they are: sk_test_ is the sandbox, sk_live_ the live application. */
    public function testASandboxKeyOnALiveSiteMeansNoVerificationAndADemoSiteTakesEither(): void
    {
        $live = SiteMode::fixed(SiteMode::LIVE);
        $demo = SiteMode::fixed(SiteMode::DEMO);
        $sandbox = new EnvironmentValues(['DIDIT_API_KEY' => 'sk_test_integration'] + self::KEYS);
        self::assertSame(['sandbox', false, true], [(new IdentitySettings($sandbox, $live))->keyKind(), (new IdentitySettings($sandbox, $live))->available(), (new IdentitySettings($sandbox, $live))->sandboxKeyOnLiveSite()]);
        self::assertTrue((new IdentitySettings($sandbox, $demo))->available(), 'A demo site tests on the sandbox.');
        try {
            $this->service($live, new IdentitySettings($sandbox, $live))->start('18');
            self::fail('No check starts on a live site with a sandbox key.');
        } catch (RuntimeException $refused) {
            self::assertSame('Identity verification is not available at the moment. Please try again later.', $refused->getMessage());
        }
        self::assertSame([], $this->requests, 'Didit is never called.');
        $liveKey = new EnvironmentValues(['DIDIT_API_KEY' => 'sk_live_integration'] + self::KEYS);
        self::assertSame(['live', true, true], [(new IdentitySettings($liveKey, $live))->keyKind(), (new IdentitySettings($liveKey, $live))->available(), (new IdentitySettings($liveKey, $demo))->available()]);
        $missing = new IdentitySettings(new EnvironmentValues(['DIDIT_API_KEY' => 'replace-with-your-key']), $demo);
        self::assertSame(['DIDIT_API_KEY', 'DIDIT_WORKFLOW_ID', 'DIDIT_WEBHOOK_SECRET'], $missing->missingKeys(), 'A copied example is not a configured site.');
        self::assertSame(['unknown', 'https://example.com/webhooks/didit', '/identity/return'], [$missing->keyKind(), (new IdentitySettings(new EnvironmentValues(self::KEYS)))->webhookUrl(), $missing->returnPath()]);
    }

    private function service(?SiteMode $site = null, ?IdentitySettings $settings = null): IdentityVerification
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => (string) ($options['body'] ?? ''), 'headers' => array_map('strtolower', array_values((array) ($options['headers'] ?? [])))];

            return array_shift($this->answers) ?? new MockResponse('{}', ['http_code' => 500]);
        });
        $settings ??= new IdentitySettings(new EnvironmentValues(self::KEYS), $site, null, '/account/verification/return');
        $accounts = new class implements IdentityAccounts {
            public function reference(string $account): string
            {
                return 'REF-' . $account;
            }
        };
        $approved = new class ($this) implements IdentityApproved {
            public function __construct(private readonly IdentityVerificationTest $test)
            {
            }

            public function approved(string $account): void
            {
                $this->test->approvals[] = $account;
            }
        };

        return new IdentityVerification($this->checks, new DiditClient($http, $settings), $settings, new \Symfony\Component\Clock\MockClock(), $this->site->activity, $accounts, $approved, 'user_id', 18);
    }

    /** Approves a check for an account as Didit would: its id. */
    private function verify(string $account, string $first, string $last, string $born = '1990-05-12'): int
    {
        $session = $this->uuid();
        $row = $this->checks->create($account, $session);
        $this->service()->apply($session, 'Approved', self::report('Approved', $first, $last, $born));

        return (int) $row['id'];
    }

    /**
     * A webhook as Didit sends it: the body and its X-Signature-V2 over the canonical JSON.
     *
     * @param array<string,mixed> $event
     * @return array{0:string,1:array<string,string>}
     */
    private function signed(array $event): array
    {
        $event['timestamp'] = time();
        $body = (string) json_encode($event, JSON_UNESCAPED_SLASHES);

        return [$body, ['x-signature-v2' => hash_hmac('sha256', (string) DiditSignature::canonical($body), self::SECRET), 'x-timestamp' => (string) time()]];
    }

    /**
     * A Didit decision report: the identity document's fields and its warnings.
     *
     * @return array<string,mixed>
     */
    private static function report(string $status, string $first = 'Thandi', string $last = 'Mokoena', string $born = '1990-05-12'): array
    {
        return [
            'status' => $status,
            'id_verifications' => [[
                'status' => $status, 'document_type' => 'Identity Card', 'document_number' => '9005125800087',
                'first_name' => $first, 'last_name' => $last, 'full_name' => $first . ' ' . $last,
                'date_of_birth' => $born, 'issuing_state' => 'ZAF', 'issuing_state_name' => 'South Africa',
                'portrait_image' => 'https://media.example.test/portrait.jpg', 'front_image' => 'https://media.example.test/front.jpg', 'warnings' => [],
            ]],
            'liveness_checks' => [['status' => $status, 'warnings' => []]],
        ];
    }

    private function uuid(): string
    {
        return (string) $this->connection->fetchOne('SELECT gen_random_uuid()::text');
    }
}
