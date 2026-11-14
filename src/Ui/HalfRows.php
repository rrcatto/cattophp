<?php

declare(strict_types=1);

namespace CattoPHP\Ui;

/**
 * Blocks of a page put in rows by their width, so a page of half and full blocks lays out the same everywhere: a full
 * block is a row of its own, two half blocks one after the other share a row (the page draws them side by side, in a
 * `layout.section-grid` of two, the same height), and a half block with no half beside it takes half a row alone. Give
 * only the blocks that are drawn, so a hidden one never leaves a gap. Static rules, not a service.
 */
final class HalfRows
{
    private function __construct()
    {
    }

    /**
     * @template T of array<string,mixed>
     * @param list<T> $blocks In page order, each with its width ('half'; anything else, or none, is full).
     * @return list<list<T>> The rows, each one block or two halves.
     */
    public static function rows(array $blocks): array
    {
        $rows = [];
        // A half block waiting for a half to share its row; any other block gives it a row of its own first.
        $waiting = null;
        foreach ($blocks as $block) {
            if (self::half($block)) {
                if ($waiting === null) {
                    $waiting = $block;
                    continue;
                }
                $rows[] = [$waiting, $block];
                $waiting = null;
                continue;
            }
            if ($waiting !== null) {
                $rows[] = [$waiting];
                $waiting = null;
            }
            $rows[] = [$block];
        }
        if ($waiting !== null) {
            $rows[] = [$waiting];
        }

        return $rows;
    }

    /** @param array<string,mixed> $block */
    private static function half(array $block): bool
    {
        return ($block['width'] ?? 'full') === 'half';
    }
}
