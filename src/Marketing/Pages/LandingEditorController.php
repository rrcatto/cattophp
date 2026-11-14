<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Pages;

use CattoPHP\Http\StockPages;
use CattoPHP\Marketing\Landing\LandingPage;
use CattoPHP\Marketing\Landing\LandingPages;
use CattoPHP\Marketing\Landing\LandingProject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * A landing page's editor under the Campaign Marketing Manager, /marketing/landing-pages/{reference} with its previews,
 * versions and actions (config/routes/marketing.php, LandingEditor::routes()). What the reader may do there is a
 * campaign's page's marketing permissions (MARKETING.VIEW to see it, MARKETING.CAMPAIGN.MANAGE to change, publish, upload
 * to it and use the saved templates), or, for an offer's page, what the project's LandingProject::access() answers.
 */
final class LandingEditorController
{
    public const ROUTE = 'cattophp_marketing_landing';

    public function __construct(
        private readonly StockPages $stock,
        private readonly LandingEditor $editor,
        private readonly LandingPages $pages,
        private readonly LandingProject $landing,
    ) {
    }

    public function answer(Request $request, string $action, string $reference): Response
    {
        if ($this->stock->reader() === null) {
            throw new AccessDeniedException('Sign in to see this page.');
        }
        $page = $this->pages->get($reference) ?? throw new NotFoundHttpException('There is no such landing page.');

        return $this->editor->answer($request, $this->place($page), $action);
    }

    /** The editor's place for a page, under CattoPHP's own routes. */
    public function place(LandingPage $page): EditorPlace
    {
        $access = $page->isCampaignPage() ? $this->editor->campaignAccess() : $this->landing->access($page, $this->stock->reader());

        return new EditorPlace(self::ROUTE, ['reference' => $page->reference], $access, $page, kicker: $page->isCampaignPage() ? 'Campaign landing page' : 'Landing page',
            backHref: $this->stock->path('cattophp_marketing_landing_pages'), backLabel: 'Every landing page');
    }
}
