<?php

declare(strict_types=1);

namespace CattoPHP\Signup;

/**
 * A registration's judgement: its score (at most 100), its band, each signal found with its points and its words, why
 * it is held for approval, a refusal to show the person, and whether the hidden field caught it.
 */
final class RiskAssessment
{
    public const LOW = 'low';
    public const MEDIUM = 'medium';
    public const HIGH = 'high';

    /**
     * @param list<array{key:string,points:int,words:string}> $signals
     * @param list<string> $holdReasons
     */
    public function __construct(
        public readonly int $score,
        public readonly string $band,
        public readonly array $signals,
        public readonly array $holdReasons,
        public readonly ?string $refusal = null,
        public readonly bool $trapped = false,
    ) {
    }

    /** Held for approval: the project decides what a hold means (a live site waits for an administrator). */
    public function holds(): bool
    {
        return $this->holdReasons !== [];
    }

    public static function band(int $score): string
    {
        return $score >= 60 ? self::HIGH : ($score >= 25 ? self::MEDIUM : self::LOW);
    }

    /** "Low risk", "Medium risk", "High risk". */
    public static function label(string $band): string
    {
        return ucfirst($band) . ' risk';
    }

    /** The badge tone each band is shown in. */
    public static function tone(string $band): string
    {
        return ['low' => 'success', 'medium' => 'warning', 'high' => 'danger'][$band] ?? 'neutral';
    }
}
