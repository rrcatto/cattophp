<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

/**
 * What a reader may do with a landing page's editor: see it (the editor, its previews and versions), change it, publish or
 * unpublish it, upload pictures to it, and use the saved templates (a marketing tool: someone editing only their own
 * offer's page sees the built-in templates alone, so nobody sees another's copy).
 */
final class LandingAccess
{
    public function __construct(
        public readonly bool $see = false,
        public readonly bool $edit = false,
        public readonly bool $publish = false,
        public readonly bool $upload = false,
        public readonly bool $savedTemplates = false,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public static function all(): self
    {
        return new self(true, true, true, true, true);
    }

    /** Sees the editor, its previews and versions, and changes nothing. */
    public static function viewOnly(): self
    {
        return new self(true);
    }
}
