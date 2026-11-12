<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Identity;

use CattoPHP\Identity\DiditSignature;
use CattoPHP\Identity\IdentityDecision;
use CattoPHP\Identity\IdentityVerification;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Didit's signatures and results, ported from cattolms's IdentityVerificationRulesTest with every case it covers. */
final class IdentityRulesTest extends TestCase
{
    private const SECRET = 'test-webhook-secret';

    public function testTheV2SignatureIsOverTheCanonicalJsonAndSurvivesReEncoding(): void
    {
        $now = 1774970000;
        // As a framework might re-encode it: keys in another order, spaces, escaped Unicode, 1.0 for 1.
        $sent = '{"status": "Approved", "session_id": "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee", "webhook_type": "status.updated", "timestamp": 1774970000, "decision": {"score": 1.0, "name": "José Müller", "url": "https://x.test/a/b", "empty": {}, "list": [2, 1]}}';
        $canonical = '{"decision":{"empty":{},"list":[2,1],"name":"José Müller","score":1,"url":"https://x.test/a/b"},"session_id":"aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee","status":"Approved","timestamp":1774970000,"webhook_type":"status.updated"}';
        self::assertSame($canonical, DiditSignature::canonical($sent));
        $v2 = hash_hmac('sha256', $canonical, self::SECRET);

        self::assertTrue(DiditSignature::verify($sent, $v2, '', (string) $now, self::SECRET, $now));
        self::assertTrue(DiditSignature::verify($sent, strtoupper($v2), '', (string) $now, self::SECRET, $now + 299), 'Within 5 minutes, in either case.');
        self::assertTrue(DiditSignature::verify($sent, '', hash_hmac('sha256', $sent, self::SECRET), (string) $now, self::SECRET, $now), 'X-Signature over the raw bytes is the fallback.');

        self::assertFalse(DiditSignature::verify($sent, $v2, '', (string) $now, self::SECRET, $now + 301), 'An old webhook is refused.');
        self::assertFalse(DiditSignature::verify($sent, $v2, '', (string) $now, 'another-secret', $now), 'Signed with another secret.');
        self::assertFalse(DiditSignature::verify(str_replace('Approved', 'Declined', $sent), $v2, '', (string) $now, self::SECRET, $now), 'An altered body is refused.');
        self::assertFalse(DiditSignature::verify($sent, '', '', (string) $now, self::SECRET, $now), 'An unsigned webhook is refused.');
        self::assertFalse(DiditSignature::verify($sent, $v2, '', 'yesterday', self::SECRET, $now), 'A timestamp must be a number.');
        self::assertFalse(DiditSignature::verify($sent, $v2, '', (string) $now, '', $now), 'Without a secret nothing is believed.');
        self::assertNull(DiditSignature::canonical('not json'));

        // For 24 hours after the secret is rotated in Didit, the replaced secret's signatures come as -Previous: a site
        // still holding the old secret believes them, and nothing else changes (Didit's webhook documentation, 2026-11-06).
        $rotated = hash_hmac('sha256', $canonical, 'the-new-secret');
        self::assertFalse(DiditSignature::verify($sent, $rotated, '', (string) $now, self::SECRET, $now), 'The new secret\'s signature alone is not this site\'s.');
        self::assertTrue(DiditSignature::verify($sent, $rotated, '', (string) $now, self::SECRET, $now, $v2), 'X-Signature-V2-Previous, made with the old secret, is.');
        self::assertTrue(DiditSignature::verify($sent, '', '', (string) $now, self::SECRET, $now, '', hash_hmac('sha256', $sent, self::SECRET)), 'And X-Signature-Previous over the raw bytes.');
        self::assertFalse(DiditSignature::verify($sent, $rotated, '', (string) $now, self::SECRET, $now, hash_hmac('sha256', $canonical, 'another-secret')), 'A -Previous signature still has to match.');
        self::assertFalse(DiditSignature::verify($sent, $rotated, '', (string) $now, self::SECRET, $now + 301, $v2), 'And the 5 minutes still apply.');
    }

    public function testAnApprovedResultKeepsOnlyTheIdentityAndNeverAnImage(): void
    {
        $report = ['status' => 'Approved', 'id_verifications' => [[
            'status' => 'Approved', 'document_type' => 'Passport', 'document_number' => 'AB1234567', 'personal_number' => '99999999R',
            'first_name' => 'María', 'last_name' => 'García López', 'full_name' => 'Maria Garcia', 'date_of_birth' => '2010-05-12',
            'issuing_state' => 'ESP', 'issuing_state_name' => 'Spain', 'portrait_image' => 'https://media.example/portrait.jpg', 'front_image' => 'https://media.example/front.jpg',
        ]]];
        $decision = IdentityDecision::from('Approved', $report);
        self::assertSame(IdentityDecision::APPROVED, $decision->status);
        self::assertSame('María García López', $decision->name, 'The name exactly as on the document.');
        self::assertSame(['Passport', 'AB1234567', 'Spain', '2010-05-12'], [$decision->documentType, $decision->documentNumber, $decision->issuingCountry, $decision->dateOfBirth]);
        self::assertNull($decision->declineReason);
        foreach ((array) $decision as $value) {
            self::assertStringNotContainsString('https://', (string) $value, 'No image link is kept.');
        }
        self::assertSame('Maria Garcia', IdentityDecision::from('Approved', ['id_verifications' => [['full_name' => 'Maria Garcia']]])->name, 'The full name when the parts are missing.');
    }

    public function testADeclinedResultSaysWhyAndOtherStatusesMapToTheSiteStatus(): void
    {
        $declined = IdentityDecision::from('Declined', ['id_verifications' => [['status' => 'Declined', 'first_name' => 'X', 'warnings' => [
            ['risk' => 'DOCUMENT_EXPIRED', 'log_type' => 'error', 'short_description' => 'Document expired', 'long_description' => 'The document has expired.'],
            ['risk' => 'LOW_QUALITY', 'log_type' => 'warning', 'short_description' => 'Low quality'],
        ]]], 'liveness_checks' => [['status' => 'Declined', 'warnings' => [['log_type' => 'error', 'short_description' => 'The selfie did not match.']]]]]);
        self::assertSame(IdentityDecision::DECLINED, $declined->status);
        self::assertSame('The document has expired. The selfie did not match.', $declined->declineReason, 'Only the hard failures, in Didit\'s words.');
        self::assertNull($declined->name, 'A declined check keeps no identity.');
        self::assertSame('Your identity could not be confirmed from the document and the selfie.', IdentityDecision::from('Declined', [])->declineReason);

        foreach (['In Review' => 'in_review', 'Abandoned' => 'abandoned', 'Expired' => 'expired', 'Kyc Expired' => 'expired', 'In Progress' => 'started', 'Not Started' => 'started', 'Resubmitted' => 'started', 'Awaiting User' => 'started', '' => 'started'] as $didit => $ours) {
            self::assertSame($ours, IdentityDecision::status($didit), $didit);
        }
    }

    public function testYoungerIsByTheDateOfBirthOnTheDay(): void
    {
        $today = new DateTimeImmutable('2026-10-19 15:00');
        self::assertTrue(IdentityVerification::younger('2008-10-20', 18, $today), 'Turns 18 tomorrow.');
        self::assertFalse(IdentityVerification::younger('2008-10-19', 18, $today), 'Turns 18 today.');
        self::assertFalse(IdentityVerification::younger('1990-01-01', 18, $today));
        self::assertFalse(IdentityVerification::younger(null, 18, $today), 'No date of birth is not younger.');
        self::assertTrue(IdentityVerification::younger('2010-05-12 00:00:00', 17, $today), 'A date read with its time: 16 until next May.');
    }

    public function testEachStatusHasItsWords(): void
    {
        self::assertSame(['Verified', 'Verified', 'Started, not finished', 'Being reviewed', 'Declined', 'Left unfinished', 'Expired', 'Revoked', 'Not verified'],
            array_map([IdentityVerification::class, 'words'], ['verified', 'approved', 'started', 'in_review', 'declined', 'abandoned', 'expired', 'revoked', 'none']));
    }
}
