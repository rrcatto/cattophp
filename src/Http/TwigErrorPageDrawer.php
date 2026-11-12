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
 * fail, as when the fault is in the theme itself, the template is drawn alone, so an error page always arrives.
 */
final class TwigErrorPageDrawer implements ErrorPageDrawer
{
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
        if ($this->pages !== null) {
            try {
                return $this->pages->renderTemplate('error', '@CattoPHP/error.html.twig', $context);
            } catch (Throwable) {
                // Drawn alone below.
            }
        }

        return $this->twig->render('@CattoPHP/error.html.twig', $context);
    }
}
