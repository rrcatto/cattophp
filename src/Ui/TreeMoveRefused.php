<?php

declare(strict_types=1);

namespace CattoPHP\Ui;

/**
 * Why TreeArrangement refused a change. The reason is a fixed code, not a sentence: each tree words it for its own
 * readers, so the rules are shared and the language is not.
 */
use InvalidArgumentException;

final class TreeMoveRefused extends InvalidArgumentException
{
    /** A row being moved is not in the tree. */
    public const MISSING_ROW = 'missing_row';
    /** Nothing was chosen to move. */
    public const NOTHING_CHOSEN = 'nothing_chosen';
    /** The destination parent is not in the tree. */
    public const MISSING_DESTINATION = 'missing_destination';
    /** The destination is the row itself or inside it. */
    public const CYCLE = 'cycle';
    /** The result would be deeper than the tree allows. */
    public const TOO_DEEP = 'too_deep';
    /** The position is outside the destination's children. */
    public const BAD_POSITION = 'bad_position';
    /** The direction is not one the tree knows. */
    public const BAD_DIRECTION = 'bad_direction';
    /** "Out" was asked of a row already at the top level. */
    public const ALREADY_OUTER = 'already_outer';
    /** The arrangement no longer matches the stored rows: the tree changed underneath. */
    public const STALE = 'stale';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
