<?php

declare(strict_types=1);

namespace CattoPHP\Inbox;

/**
 * The Inbox's Bayesian word filter, trained by Mark as spam and Not spam beside the fixed signs. Pure: it splits a message
 * into its words, and from how often each word was in the spam and the genuine messages it was taught with, gives the
 * chance the message is spam. Each word's chance is Robinson's, pulled towards a half while the word is rare; the strongest
 * words are combined by Fisher's method, as SpamBayes does, so a message with spammy and genuine words is unsure rather than
 * either. How much the result counts grows with what the filter has learnt (confidence()).
 */
final class SpamFilter
{
    /** The words a message is judged by: the most telling ones, as SpamBayes takes them. */
    public const MAX_WORDS = 15;
    /** A word's strength towards a half while seen this rarely (Robinson's s), and the half itself (x). */
    private const STRENGTH = 1.0;
    private const UNKNOWN = 0.5;
    /** Messages of each kind it must have learnt from before it counts fully. */
    public const FULL_CONFIDENCE = 20;

    /**
     * A message's words, once each: lower case, 3 to 30 letters or digits, and each web or email address's domain as
     * "site:example.com", so a spammer's link counts however it is worded.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $text = mb_strtolower($text);
        $words = [];
        if (preg_match_all('#(?:https?://|www\.)([a-z0-9.-]+\.[a-z]{2,})|[a-z0-9._%+-]+@([a-z0-9.-]+\.[a-z]{2,})#u', $text, $sites, PREG_SET_ORDER) > 0) {
            foreach ($sites as $site) { $words['site:' . ltrim((string) (($site[1] ?? '') !== '' ? $site[1] : ($site[2] ?? '')), '.')] = true; }
        }
        foreach (preg_split('/[^\p{L}\p{N}\']+/u', $text) ?: [] as $word) {
            $word = trim($word, "'");
            $length = mb_strlen($word);
            if ($length < 3 || $length > 30 || preg_match('/^\d+$/', $word) === 1) continue;
            $words[$word] = true;
        }
        return array_keys($words);
    }

    /**
     * The chance a message is spam, 0 to 1, from its words' counts.
     *
     * @param list<string> $words
     * @param array<string,array{0:int,1:int}> $counts each known word's [spam messages, genuine messages] it was in
     */
    public static function probability(array $words, array $counts, int $spamMessages, int $hamMessages): float
    {
        if ($spamMessages < 1 || $hamMessages < 1) return self::UNKNOWN;
        $chances = [];
        foreach ($words as $word) {
            [$spam, $ham] = $counts[$word] ?? [0, 0];
            $seen = $spam + $ham;
            if ($seen === 0) continue;
            $spamRate = min(1.0, $spam / $spamMessages);
            $hamRate = min(1.0, $ham / $hamMessages);
            $chance = $spamRate + $hamRate > 0 ? $spamRate / ($spamRate + $hamRate) : self::UNKNOWN;
            $chances[] = (self::STRENGTH * self::UNKNOWN + $seen * $chance) / (self::STRENGTH + $seen);
        }
        if ($chances === []) return self::UNKNOWN;
        usort($chances, static fn(float $a, float $b): int => abs($b - 0.5) <=> abs($a - 0.5));
        $chances = array_map(static fn(float $p): float => min(0.99, max(0.01, $p)), array_slice($chances, 0, self::MAX_WORDS));
        $n = count($chances);
        $spamminess = 1.0 - self::chi2Q(-2.0 * array_sum(array_map(static fn(float $p): float => log(1.0 - $p), $chances)), 2 * $n);
        $hamminess = 1.0 - self::chi2Q(-2.0 * array_sum(array_map(static fn(float $p): float => log($p), $chances)), 2 * $n);
        return max(0.0, min(1.0, ($spamminess - $hamminess + 1.0) / 2.0));
    }

    /** How much the filter's result counts, 0 to 1: nothing until it has learnt from both kinds, fully from 20 of each. */
    public static function confidence(int $spamMessages, int $hamMessages): float
    {
        return min(1.0, min($spamMessages, $hamMessages) / self::FULL_CONFIDENCE);
    }

    /** The chance a chi-squared value this large or larger arises with this many degrees of freedom (even). */
    private static function chi2Q(float $chi2, int $freedom): float
    {
        $m = $chi2 / 2.0;
        $sum = $term = exp(-$m);
        for ($i = 1; $i < intdiv($freedom, 2); $i++) {
            $term *= $m / $i;
            $sum += $term;
        }
        return min($sum, 1.0);
    }
}
