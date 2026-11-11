<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use CattoPHP\Http\HtmlLayout;
use CattoPHP\Twig\UiExtension;
use CattoPHP\Ui\Components;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/** Draws a component through the real registry and its templates, in a strict Twig like a project's. */
final class UiHarness
{
    public static function components(): Components
    {
        return new Components();
    }

    public static function twig(?Components $components = null): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__, 2) . '/templates', 'CattoPHP');
        $twig = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'html', 'cache' => false]);
        $twig->addExtension(new UiExtension($components ?? self::components()));
        // CattoPHPExtension's authored, as a project has it: HTML an author wrote, given as it is outside the page's layout.
        $twig->addFilter(new TwigFilter('authored', (new HtmlLayout())->asWritten(...), ['is_safe' => ['html']]));

        return $twig;
    }

    /** @param array<string,mixed> $properties */
    public static function render(string $component, array $properties = [], ?Components $components = null): string
    {
        $components ??= self::components();

        return self::twig($components)->render($components->template($component), ['props' => $components->properties($component, $properties)]);
    }

    /** @param array<string,mixed> $context */
    public static function source(string $source, array $context = [], ?Components $components = null): string
    {
        return self::twig($components)->createTemplate($source)->render($context);
    }

    public static function xpath(string $html): HtmlQuery
    {
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');

        return new HtmlQuery(new \DOMXPath($document));
    }
}
