<?php

declare(strict_types=1);

namespace CattoPHP\Http;

use CattoPHP\Theme\PageRenderer;
use Symfony\Component\HttpFoundation\Request;
use Throwable;
use Twig\Environment;

/**
 * CattoPHP's own error page, which a project may also draw by overriding templates/bundles/CattoPHPBundle/error.html.twig.
 * A project with themes draws it in its theme (the page named error, whose template may extend `layout`); should that
 * fail, as when the fault is in the theme itself, the template is drawn in CattoPHP's plain frame instead, given as
 * `layout` (a template written for a theme extends `layout` and fills page_body, which the plain frame draws too), so an
 * error page always arrives.
 */
final class TwigErrorPageDrawer implements ErrorPageDrawer
{
    /** The frame the page is drawn in when the theme cannot draw it: CattoPHP's plainest, which needs nothing of the project's. */
    public const PLAIN_FRAME = '@CattoPHP/layout.html.twig';

    public function __construct(private readonly Environment $twig, private readonly ?PageRenderer $pages = null)
    {
    }

    public function draw(ErrorPage $page, Request $request): string
    {
        $context = [
            'title' => $page->title,
            'kind' => $page->kind,
            'status' => $page->status,
            'message' => $page->message,
            'reference' => $page->reference,
            'retry_url' => $page->retryUrl,
        ];
        if ($this->pages === null) {
            // A project without themes: the template extends the project's layout (cattophp.layout).
            return $this->twig->render('@CattoPHP/error.html.twig', $context);
        }
        try {
            return $this->pages->renderTemplate('error', '@CattoPHP/error.html.twig', $context);
        } catch (Throwable) {
            return $this->twig->render('@CattoPHP/error.html.twig', $context + ['layout' => self::PLAIN_FRAME]);
        }
    }
}
