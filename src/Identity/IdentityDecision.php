<?php

declare(strict_types=1);

namespace CattoPHP\Identity;

/**
 * What is kept of a Didit result: the status, and for an approved check the verified name exactly as on the identity
 * document, the document type, number and issuing country and the date of birth. Nothing else is read from it, and no
 * image is ever kept (the report's images are links that are never followed). A declined check keeps why, in the plain
 * words Didit gives the person verifying. Statuses on the way to a decision (Not Started, In Progress, Awaiting User,
 * Resubmitted) leave the check started.
 */
final class IdentityDecision
{
    public const STARTED = 'started';
    public const IN_REVIEW = 'in_review';
    public const APPROVED = 'approved';
    public const DECLINED = 'declined';
    public const ABANDONED = 'abandoned';
    public const EXPIRED = 'expired';
    public const REVOKED = 'revoked';

    /** Statuses after which nothing more happens to a check unless Didit's reviewers decide. */
    public const FINAL = [self::APPROVED, self::DECLINED, self::ABANDONED, self::EXPIRED];

    private function __construct(
        public readonly string $status,
        public readonly ?string $name,
        public readonly ?string $documentType,
        public readonly ?string $documentNumber,
        public readonly ?string $issuingCountry,
        public readonly ?string $dateOfBirth,
        public readonly ?string $declineReason,
    ) {}

    /**
     * @param array<string,mixed> $decision the decision report (GET .../decision/ or a webhook's decision)
     */
    public static function from(string $diditStatus, array $decision): self
    {
        $status = self::status($diditStatus);
        $id = self::idVerification($decision);
        if ($status === self::APPROVED) {
            $name = self::text(trim(self::text($id['first_name'] ?? null) . ' ' . self::text($id['last_name'] ?? null)), 240) ?: self::text($id['full_name'] ?? null, 240);
            $birth = self::text($id['date_of_birth'] ?? null);
            return new self($status, $name ?: null, self::text($id['document_type'] ?? null, 80) ?: null,
                (self::text($id['document_number'] ?? null, 80) ?: self::text($id['personal_number'] ?? null, 80)) ?: null,
                (self::text($id['issuing_state_name'] ?? null, 80) ?: self::text($id['issuing_state'] ?? null, 80)) ?: null,
                preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth) === 1 ? $birth : null, null);
        }
        return new self($status, null, null, null, null, null, $status === self::DECLINED ? self::reason($decision) : null);
    }

    /** Didit's session status as the site's status. */
    public static function status(string $diditStatus): string
    {
        return match (trim($diditStatus)) {
            'Approved' => self::APPROVED,
            'Declined' => self::DECLINED,
            'In Review' => self::IN_REVIEW,
            'Abandoned' => self::ABANDONED,
            'Expired', 'Kyc Expired' => self::EXPIRED,
            default => self::STARTED,
        };
    }

    /**
     * @param array<string,mixed> $decision
     * @return array<string,mixed> the first identity document's report
     */
    private static function idVerification(array $decision): array
    {
        $reports = $decision['id_verifications'] ?? null;
        if (is_array($reports) && isset($reports[0]) && is_array($reports[0])) return $reports[0];
        // A destination pinned to Didit's version 2 webhooks sends one report rather than a list.
        return is_array($decision['id_verification'] ?? null) ? $decision['id_verification'] : [];
    }

    /**
     * Why a check was declined, in Didit's words for the person verifying, from the hard failures it
     * reports. The same reason is given once.
     *
     * @param array<string,mixed> $decision
     */
    private static function reason(array $decision): string
    {
        $reasons = [];
        foreach ($decision as $reports) {
            if (!is_array($reports)) continue;
            foreach (array_is_list($reports) ? $reports : [$reports] as $report) {
                if (!is_array($report)) continue;
                foreach ((array) ($report['warnings'] ?? []) as $warning) {
                    if (!is_array($warning) || ($warning['log_type'] ?? '') !== 'error') continue;
                    $words = self::text($warning['long_description'] ?? null) ?: self::text($warning['short_description'] ?? null);
                    if ($words !== '') $reasons[$words] = true;
                }
            }
        }
        $text = $reasons === [] ? 'Your identity could not be confirmed from the document and the selfie.' : implode(' ', array_keys($reasons));
        return mb_substr($text, 0, 500);
    }

    private static function text(mixed $value, int $limit = 500): string
    {
        return is_scalar($value) ? mb_substr(trim((string) $value), 0, $limit) : '';
    }
}
