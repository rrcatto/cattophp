<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Ui;

use CattoPHP\Ui\HalfRows;
use PHPUnit\Framework\TestCase;

/**
 * The one rule that puts a page's blocks in rows: a full block alone, two half blocks one after the other side by side,
 * a half block with no half beside it alone.
 */
final class HalfRowsTest extends TestCase
{
    public function testHalvesPairAndAFullBlockStandsAlone(): void
    {
        self::assertSame([['b0', 'b1'], ['b2']], self::rows(self::blocks('half', 'half', 'full')));
        self::assertSame([['b0'], ['b1'], ['b2', 'b3']], self::rows(self::blocks('half', 'full', 'half', 'half')), 'A half before a full block has its row alone.');
        self::assertSame([['b0', 'b1'], ['b2']], self::rows(self::blocks('half', 'half', 'half')), 'A third half starts a row of its own.');
        self::assertSame([['b0'], ['b1']], self::rows([['id' => 'b0'], ['id' => 'b1', 'width' => 'other']]), 'Without a half width a block is full.');
        self::assertSame([], HalfRows::rows([]));
    }

    public function testEachBlockComesBackWholeInPageOrder(): void
    {
        $blocks = [['id' => 'hero', 'width' => 'full', 'title' => 'Welcome'], ['id' => 'words', 'width' => 'half', 'title' => 'Why'], ['id' => 'faq', 'width' => 'half', 'title' => 'Questions']];
        self::assertSame([[$blocks[0]], [$blocks[1], $blocks[2]]], HalfRows::rows($blocks), 'The blocks are kept as given, with all they hold.');
    }

    /** @return list<array<string,mixed>> */
    private static function blocks(string ...$widths): array
    {
        $blocks = [];
        foreach (array_values($widths) as $i => $width) {
            $blocks[] = ['id' => 'b' . $i, 'width' => $width];
        }

        return $blocks;
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return list<list<mixed>>
     */
    private static function rows(array $blocks): array
    {
        return array_map(static fn(array $row): array => array_column($row, 'id'), HalfRows::rows($blocks));
    }
}
