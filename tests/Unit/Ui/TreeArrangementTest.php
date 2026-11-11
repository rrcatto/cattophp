<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Ui;

use CattoPHP\Ui\TreeArrangement;
use CattoPHP\Ui\TreeMoveRefused;
use PHPUnit\Framework\TestCase;

/**
 * The tree rules every arranged tree shares, without a database:
 *
 *   1 > 2 > 3, 1 > 4      5 > 6      7
 */
final class TreeArrangementTest extends TestCase
{
    private const ROWS = [
        ['id' => 1, 'parent' => null], ['id' => 2, 'parent' => 1], ['id' => 3, 'parent' => 2], ['id' => 4, 'parent' => 1],
        ['id' => 5, 'parent' => null], ['id' => 6, 'parent' => 5], ['id' => 7, 'parent' => null],
    ];

    public function testPlacingMovesWholeSubtreesAndReturnsTreeOrder(): void
    {
        $tree = new TreeArrangement(3);
        $rows = $tree->place(self::ROWS, [2], 5, 0);
        self::assertSame([1, 4, 5, 2, 3, 6, 7], array_column($rows, 'id'), 'The subtree 2 > 3 moves together, first under 5.');
        self::assertSame(['parent' => 5, 'depth' => 2, 'position' => 1], array_intersect_key($tree->levelled($rows)[3], ['parent' => 0, 'depth' => 0, 'position' => 0]));
        self::assertSame([5, 3], [self::row($tree->levelled($rows), 2)['parent'], self::row($tree->levelled($rows), 3)['depth']]);
        $rows = $tree->place(self::ROWS, [7], null, 0);
        self::assertSame([7, 1, 2, 3, 4, 5, 6], array_column($rows, 'id'), 'Reordering at the top level.');
        $rows = $tree->place(self::ROWS, [3], null, null);
        self::assertSame(1, self::row($tree->levelled($rows), 3)['depth'], 'A deepest row moved out to the top level.');
    }

    public function testDepthCountsTheWholeSubtreeAndCyclesAreRefused(): void
    {
        $tree = new TreeArrangement(3);
        foreach ([[[7], 3, TreeMoveRefused::TOO_DEEP], [[2], 6, TreeMoveRefused::TOO_DEEP], [[1], 5, TreeMoveRefused::TOO_DEEP], [[1], 1, TreeMoveRefused::CYCLE], [[1], 3, TreeMoveRefused::CYCLE], [[99], null, TreeMoveRefused::MISSING_ROW], [[7], 99, TreeMoveRefused::MISSING_DESTINATION]] as [$ids, $parent, $reason]) {
            try {
                $tree->place(self::ROWS, $ids, $parent, null);
                self::fail('Refused: ' . $reason);
            } catch (TreeMoveRefused $refused) {
                self::assertSame($reason, $refused->reason);
            }
        }
        try {
            $tree->place(self::ROWS, [7], 1, 5);
            self::fail('A position past the end is refused.');
        } catch (TreeMoveRefused $refused) {
            self::assertSame(TreeMoveRefused::BAD_POSITION, $refused->reason);
        }
        self::assertSame([null, 1, 2, 4, 5, 6], $tree->destinations(self::ROWS, [7]), 'A leaf may go anywhere but under a third-level row.');
        self::assertSame([null, 1, 5, 7], $tree->destinations(self::ROWS, [2]), 'A two-level subtree only under a main row, its own parent included.');
        self::assertSame([1 => 3, 2 => 2, 3 => 1, 4 => 1, 5 => 2, 6 => 1, 7 => 1], $tree->heights(self::ROWS));
    }

    public function testDirectionalMovesAndTheOuterLevel(): void
    {
        $tree = new TreeArrangement(3);
        self::assertSame([1, 4, 2, 3, 5, 6, 7], array_column($tree->move(self::ROWS, [4], 'up'), 'id'));
        self::assertSame([5, 6, 1, 2, 3, 4, 7], array_column($tree->move(self::ROWS, [5], 'top'), 'id'));
        self::assertSame([5, 6, 7, 1, 2, 3, 4], array_column($tree->move(self::ROWS, [1], 'bottom'), 'id'));
        $out = $tree->move(self::ROWS, [3], 'out');
        self::assertSame([1, 2, 3, 4, 5, 6, 7], array_column($out, 'id'));
        self::assertSame(['parent' => 1, 'depth' => 2, 'position' => 2], array_intersect_key(self::row($tree->levelled($out), 3), ['parent' => 0, 'depth' => 0, 'position' => 0]), 'Out puts it just after its former parent.');
        self::assertSame(self::ROWS, $tree->move(self::ROWS, [1], 'up'), 'The first row cannot go further up; nothing changes.');
        try {
            $tree->move(self::ROWS, [1], 'out');
            self::fail('A top-level row cannot move out.');
        } catch (TreeMoveRefused $refused) {
            self::assertSame(TreeMoveRefused::ALREADY_OUTER, $refused->reason);
        }
    }

    public function testACompleteTreeIsConfirmedAndAChangedOneIsStale(): void
    {
        $tree = new TreeArrangement(3);
        $tree->assertComplete([1, 2, 3, 4, 5, 6, 7], self::ROWS);
        foreach ([[[1, 2, 3, 4, 5, 6, 7, 8], self::ROWS], [[1, 2, 3, 4, 5, 6, 7], array_reverse(self::ROWS)]] as [$known, $rows]) {
            try {
                $tree->assertComplete($known, $rows);
                self::fail('A tree that does not match the stored rows is stale.');
            } catch (TreeMoveRefused $refused) {
                self::assertSame(TreeMoveRefused::STALE, $refused->reason);
            }
        }
        $positions = array_column($tree->levelled(self::ROWS), 'position', 'id');
        self::assertSame([1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 2, 6 => 1, 7 => 3], $positions, 'Positions run 1..n within each parent.');
    }

    /**
     * @param list<array{id:int,parent:int|null,depth:int,position:int}> $rows
     * @return array{id:int,parent:int|null,depth:int,position:int}
     */
    private static function row(array $rows, int $id): array
    {
        foreach ($rows as $row) {
            if ($row['id'] === $id) return $row;
        }
        self::fail('No row ' . $id);
    }
}
