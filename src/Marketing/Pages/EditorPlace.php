<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Pages;

use CattoPHP\Marketing\Landing\LandingAccess;
use CattoPHP\Marketing\Landing\LandingPage;

/**
 * Where a landing page's editor is mounted, for LandingEditor: the common name of its routes (as LandingEditor::routes()
 * made them, the editor itself being `<route>_edit`) and their parameters, what the reader may do there (a
 * LandingAccess: CattoPHP's own routes answer it from the marketing permissions for a campaign's page and from
 * LandingProject::access() for an offer's; a project mounting the editor under its own address answers it by its own
 * rule), and the page head's small line and back link. It holds the page, or, for an offer that has no page yet, the
 * offer to make one for (the editor then offers to make it).
 */
final class EditorPlace
{
    /** @param array<string,string> $parameters the routes' parameters, the same for every route of the editor */
    public function __construct(
        public readonly string $route,
        public readonly array $parameters,
        public readonly LandingAccess $access,
        public readonly ?LandingPage $page = null,
        public readonly ?string $offerType = null,
        public readonly ?string $offerReference = null,
        public readonly string $kicker = 'Landing page',
        public readonly string $backHref = '',
        public readonly string $backLabel = '',
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $route) !== 1) {
            throw new \InvalidArgumentException('An editor\'s routes are named by small letters, digits and underscores, such as shop_landing.');
        }
        if ($page === null && ($offerType === null || $offerReference === null)) {
            throw new \InvalidArgumentException('An editor is for a page, or for an offer to make a page for.');
        }
        if ($backHref !== '' && preg_match('#^/(?!/)#', $backHref) !== 1) {
            throw new \InvalidArgumentException('The back link is a path on this site.');
        }
    }

    /** The same place for the page made for it, or changed. */
    public function withPage(LandingPage $page): self
    {
        return new self($this->route, $this->parameters, $this->access, $page, $page->offerType ?? $this->offerType, $page->offerReference ?? $this->offerReference, $this->kicker,
            $this->backHref, $this->backLabel);
    }

    /** The name of one of its routes: edit, preview, section_save and the rest (LandingEditor::ROUTES). */
    public function routeName(string $action): string
    {
        return $this->route . '_' . $action;
    }
}
