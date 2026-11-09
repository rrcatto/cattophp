<?php

declare(strict_types=1);

namespace CattoPHP\Http;

use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

/** CattoPHP's own error page, which a project may also draw by overriding templates/bundles/CattoPHPBundle/error.html.twig. */
final class TwigErrorPageDrawer implements ErrorPageDrawer
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function draw(ErrorPage $page, Request $request): string
    {
        return $this->twig->render('@CattoPHP/error.html.twig', [
            'title' => $page->title,
            'kind' => $page->kind,
            'status' => $page->status,
            'message' => $page->message,
            'reference' => $page->reference,
            'retry_url' => $page->retryUrl,
        ]);
    }
}
