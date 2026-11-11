<?php

declare(strict_types=1);

namespace CattoPHP\Ui;

/**
 * Ordered tree operations with a depth limit, for any tree a project lets its readers arrange by dragging, by keyboard or
 * with a menu that works without JavaScript. Rows are `{id, parent}` in tree order: each row is followed by its
 * descendants, and the order of a parent's children is the order they appear in. Every operation returns a new, complete
 * tree in tree order or throws TreeMoveRefused with a reason code; nothing here touches the database or words a message,
 * so each tree validates a change in full before it saves it and explains a refusal in its own terms.
 *
 * A moved row always takes its whole subtree, so a move checks the destination's depth plus the height of what is moving,
 * never the moved row alone, and a row can never be placed inside itself or one of its descendants.
 */
final class TreeArrangement
{
    public const DIRECTIONS = ['top', 'up', 'down', 'bottom', 'out'];

    public function __construct(public readonly int $maxDepth)
    {
    }

    /**
     * Places the given rows, each with its whole subtree, under $parentId (null for the top level)
     * at $index among the parent's remaining children (null appends). A chosen row inside another
     * chosen row travels with that ancestor. The rows keep their current relative order.
     *
     * @param list<array{id:int,parent:int|null}> $rows
     * @param list<int> $ids
     * @return list<array{id:int,parent:int|null}>
     */
    public function place(array $rows, array $ids, ?int $parentId, ?int $index = null): array
    {
        [$byId, $children] = $this->index($rows);
        $moving = $this->outermost($rows, $byId, $ids);
        if ($parentId !== null) {
            if (!isset($byId[$parentId])) { throw new TreeMoveRefused(TreeMoveRefused::MISSING_DESTINATION); }
            foreach ($moving as $id) {
                if ($this->isWithin($byId, $parentId, $id)) { throw new TreeMoveRefused(TreeMoveRefused::CYCLE); }
            }
        }
        $height = max(array_map(fn(int $id): int => $this->height($children, $id), $moving));
        if ($this->depthOf($byId, $parentId) + $height > $this->maxDepth) { throw new TreeMoveRefused(TreeMoveRefused::TOO_DEEP); }
        foreach ($moving as $id) {
            $from = $byId[$id]['parent'] ?? 0;
            $children[$from] = array_values(array_diff($children[$from], [$id]));
        }
        $target = $children[$parentId ?? 0] ?? [];
        $index ??= count($target);
        if ($index < 0 || $index > count($target)) { throw new TreeMoveRefused(TreeMoveRefused::BAD_POSITION); }
        array_splice($target, $index, 0, $moving);
        $children[$parentId ?? 0] = $target;
        foreach ($moving as $id) { $byId[$id] = ['id' => $id, 'parent' => $parentId]; }
        return $this->walk($byId, $children);
    }

    /**
     * Top, up, down and bottom move within the current parent; out places a row immediately after
     * its former parent. Moving several rows treats each outermost chosen row in turn, so a group
     * keeps its order and a row blocked by the edge does not swap with a chosen neighbour.
     *
     * @param list<array{id:int,parent:int|null}> $rows
     * @param list<int> $ids
     * @return list<array{id:int,parent:int|null}>
     */
    public function move(array $rows, array $ids, string $direction): array
    {
        if (!in_array($direction, self::DIRECTIONS, true)) { throw new TreeMoveRefused(TreeMoveRefused::BAD_DIRECTION); }
        [$byId] = $this->index($rows);
        $moving = $this->outermost($rows, $byId, $ids);
        if ($direction === 'out') {
            foreach ($moving as $id) {
                if ($byId[$id]['parent'] === null) { throw new TreeMoveRefused(TreeMoveRefused::ALREADY_OUTER); }
            }
        }
        $selected = array_fill_keys($moving, true);
        $order = in_array($direction, ['down', 'top', 'out'], true) ? array_reverse($moving) : $moving;
        foreach ($order as $id) {
            [$byId, $children] = $this->index($rows);
            $parent = $byId[$id]['parent'];
            $siblings = $children[$parent ?? 0];
            $at = (int) array_search($id, $siblings, true);
            $destination = $parent;
            $index = match ($direction) {
                'top' => 0,
                'bottom' => null,
                'up' => $at > 0 && !isset($selected[$siblings[$at - 1]]) ? $at - 1 : $at,
                'down' => isset($siblings[$at + 1]) && !isset($selected[$siblings[$at + 1]]) ? $at + 1 : $at,
                default => null,
            };
            if ($direction === 'out') {
                $destination = $byId[(int) $parent]['parent'];
                $index = (int) array_search($parent, $children[$destination ?? 0], true) + 1;
            }
            // A row at the edge stays chosen, so the chosen row behind it does not jump past it.
            if ($index === $at && $destination === $parent) { continue; }
            $rows = $this->place($rows, [$id], $destination, $index);
            unset($selected[$id]);
        }
        return $rows;
    }

    /**
     * Destinations that can take the given rows: the top level (null) and every row that is not
     * one of them or inside them and leaves room for their deepest descendant.
     *
     * @param list<array{id:int,parent:int|null}> $rows
     * @param list<int> $ids
     * @return list<int|null>
     */
    public function destinations(array $rows, array $ids): array
    {
        [$byId, $children] = $this->index($rows);
        $moving = $this->outermost($rows, $byId, $ids);
        $height = max(array_map(fn(int $id): int => $this->height($children, $id), $moving));
        $valid = [null];
        foreach ($rows as $row) {
            $id = $row['id'];
            $inside = array_filter($moving, fn(int $moved): bool => $this->isWithin($byId, $id, $moved)) !== [];
            if (!$inside && $this->depthOf($byId, $id) + $height <= $this->maxDepth) { $valid[] = $id; }
        }
        return $valid;
    }

    /**
     * Confirms that $rows is a complete, acyclic arrangement of exactly the rows $known, in tree
     * order and within the depth limit. A mismatch means the stored tree changed underneath.
     *
     * @param list<int> $known
     * @param list<array{id:int,parent:int|null}> $rows
     */
    public function assertComplete(array $known, array $rows): void
    {
        $known = array_fill_keys($known, true);
        if (count($known) !== count($rows)) { throw new TreeMoveRefused(TreeMoveRefused::STALE); }
        $depths = [];
        foreach ($rows as $row) {
            $id = $row['id']; $parent = $row['parent'];
            if (!isset($known[$id]) || isset($depths[$id]) || ($parent !== null && !isset($depths[$parent]))) { throw new TreeMoveRefused(TreeMoveRefused::STALE); }
            $depths[$id] = $parent === null ? 1 : $depths[$parent] + 1;
            if ($depths[$id] > $this->maxDepth) { throw new TreeMoveRefused(TreeMoveRefused::TOO_DEEP); }
        }
    }

    /**
     * Each row's depth (1 at the top level) and its position among its siblings (from 1), in tree
     * order: what a store keeps for every row after a change.
     *
     * @param list<array{id:int,parent:int|null}> $rows
     * @return list<array{id:int,parent:int|null,depth:int,position:int}>
     */
    public function levelled(array $rows): array
    {
        $depths = []; $next = []; $result = [];
        foreach ($rows as $row) {
            $parent = $row['parent'];
            $depths[$row['id']] = $parent === null ? 1 : ($depths[$parent] ?? 0) + 1;
            $next[$parent ?? 0] = ($next[$parent ?? 0] ?? 0) + 1;
            $result[] = $row + ['depth' => $depths[$row['id']], 'position' => $next[$parent ?? 0]];
        }
        return $result;
    }

    /**
     * Height of every row's subtree, counting the row itself as 1.
     *
     * @param list<array{id:int,parent:int|null}> $rows
     * @return array<int,int>
     */
    public function heights(array $rows): array
    {
        [, $children] = $this->index($rows);
        $heights = [];
        foreach ($rows as $row) { $heights[$row['id']] = $this->height($children, $row['id']); }
        return $heights;
    }

    /**
     * @param list<array{id:int,parent:int|null}> $rows
     * @return array{0:array<int,array{id:int,parent:int|null}>,1:array<int,list<int>>}
     */
    private function index(array $rows): array
    {
        $byId = []; $children = [0 => []];
        foreach ($rows as $row) {
            $byId[$row['id']] = $row;
            $children[$row['parent'] ?? 0][] = $row['id'];
        }
        return [$byId, $children];
    }

    /**
     * @param list<array{id:int,parent:int|null}> $rows
     * @param array<int,array{id:int,parent:int|null}> $byId
     * @param list<int> $ids
     * @return non-empty-list<int>
     */
    private function outermost(array $rows, array $byId, array $ids): array
    {
        $selected = [];
        foreach ($ids as $id) {
            if (!isset($byId[$id])) { throw new TreeMoveRefused(TreeMoveRefused::MISSING_ROW); }
            $selected[$id] = true;
        }
        $moving = [];
        foreach ($rows as $row) {
            if (!isset($selected[$row['id']])) { continue; }
            $ancestor = $row['parent']; $covered = false;
            while ($ancestor !== null) { if (isset($selected[$ancestor])) { $covered = true; break; } $ancestor = $byId[$ancestor]['parent']; }
            if (!$covered) { $moving[] = $row['id']; }
        }
        if ($moving === []) { throw new TreeMoveRefused(TreeMoveRefused::NOTHING_CHOSEN); }
        return $moving;
    }

    /** @param array<int,array{id:int,parent:int|null}> $byId */
    private function isWithin(array $byId, int $id, int $ancestor): bool
    {
        for ($current = $id; $current !== null; $current = $byId[$current]['parent']) {
            if ($current === $ancestor) { return true; }
        }
        return false;
    }

    /** @param array<int,array{id:int,parent:int|null}> $byId */
    private function depthOf(array $byId, ?int $id): int
    {
        $depth = 0;
        for ($current = $id; $current !== null; $current = $byId[$current]['parent']) { $depth++; }
        return $depth;
    }

    /** @param array<int,list<int>> $children */
    private function height(array $children, int $id): int
    {
        $height = 1;
        foreach ($children[$id] ?? [] as $child) { $height = max($height, 1 + $this->height($children, $child)); }
        return $height;
    }

    /**
     * @param array<int,array{id:int,parent:int|null}> $byId
     * @param array<int,list<int>> $children
     * @return list<array{id:int,parent:int|null}>
     */
    private function walk(array $byId, array $children): array
    {
        $result = [];
        $visit = function (int $parent) use (&$visit, &$result, $byId, $children): void {
            foreach ($children[$parent] ?? [] as $id) { $result[] = $byId[$id]; $visit($id); }
        };
        $visit(0);
        return $result;
    }
}
