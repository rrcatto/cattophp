<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel\App;

use CattoPHP\Http\StockPages;
use CattoPHP\Marketing\Landing\LandingAccess;
use CattoPHP\Marketing\Landing\LandingPages;
use CattoPHP\Marketing\MarketingPermissions;
use CattoPHP\Marketing\MarketingProject;
use CattoPHP\Marketing\Pages\EditorPlace;
use CattoPHP\Marketing\Pages\LandingEditor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The test shop's own place for a product's landing page editor, /shop/{reference}/landing, mounted as a project mounts
 * it (LandingEditor::routes() with the page made here): its shopkeeper (account 2) may change a product's page and upload
 * to it without any of the marketing permissions, but neither publishes it nor sees the saved templates; whoever manages
 * campaigns may do everything; anyone else, nothing.
 */
final class ExampleShopLanding
{
    public const ROUTE = 'example_shop_landing';

    public function __construct(private readonly StockPages $stock, private readonly LandingEditor $editor, private readonly LandingPages $pages, private readonly MarketingProject $project)
    {
    }

    public function answer(Request $request, string $action, string $reference): Response
    {
        if ($this->project->offer('product', $reference) === null) {
            throw new NotFoundHttpException('There is no such product.');
        }
        $reader = $this->stock->reader();
        $access = match (true) {
            $this->stock->may(MarketingPermissions::MANAGE) => LandingAccess::all(),
            $reader !== null && $this->stock->actor()->id === '2' => new LandingAccess(see: true, edit: true, publish: false, upload: true, savedTemplates: false),
            default => LandingAccess::none(),
        };
        $place = new EditorPlace(self::ROUTE, ['reference' => $reference], $access, $this->pages->forOffer('product', $reference), 'product', $reference, kicker: 'The shop\'s landing page',
            backHref: '/', backLabel: 'Back to the shop');

        return $this->editor->answer($request, $place, $action);
    }
}
