<?php

declare(strict_types=1);

namespace CattoPHP\Theme;

use CattoPHP\Http\HtmlLayout;
use RuntimeException;
use Twig\Environment;

/**
 * Draws a project's page in the theme it is shown in. The page's body is the project's (`@platform/pages/<page>`); it
 * extends `layout`, the theme's wrapper for its family or the theme's base (or a layout of the project's own), so a page
 * never knows which themes have wrappers. Every page goes the same way:
 *
 * - the theme: the active one, or another installed one the reader may preview (ThemeManager::resolve()), with Twig's
 *   `@theme` pointed at it;
 * - the data checked: names a template can use, values that are text, numbers, true or false, lists and nothing else, so
 *   no object with behaviour reaches a theme;
 * - the view model: the theme's part (its name, version, stylesheets and scripts, colour scheme, whether this is a
 *   preview) and the project's (PageProject::model());
 * - the page as it is served: the generator tag first in the head, then the whole page laid out by HtmlLayout, which
 *   changes whitespace only where a browser draws nothing differently. On a development site a request with
 *   `X-Served-Html: as-built` gets the page as Twig drew it, so a check can compare the two.
 */
final class PageRenderer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly Templates $templates,
        private readonly ThemeManager $themes,
        private readonly PageProject $project,
        private readonly ?HtmlLayout $layout = null,
        private readonly bool $debug = false,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function render(string $page, array $data = []): string
    {
        self::assertName($page, 'page template');
        self::assertData($data);

        [$themeKey, $previewActive] = $this->resolveTheme($data);
        $family = $this->project->family($page);
        $data = $this->project->prepare($page, $family, $data);
        $context = $this->viewModel($page, $data, $themeKey, $previewActive);
        $context['layout'] = $this->project->layout($family) ?? $this->templates->layoutFor($family);

        // While the page is drawn, |authored marks the project's authored HTML for the layout (HtmlLayout::asWritten).
        $this->layout?->begin();
        try {
            $html = $this->twig->render($this->templates->pageTemplate($page), $context);
        } finally {
            $this->layout?->end();
        }

        return $this->served($html);
    }

    /**
     * One template of the project's as a bare piece, without the theme: what htmx swaps into a page. It may read every
     * value a page may (PageProject::scalars()), which the caller's data can still replace.
     *
     * @param array<string,mixed> $data
     */
    public function renderFragment(string $template, array $data = []): string
    {
        self::assertName($template, 'fragment template');
        self::assertData($data);

        return $this->twig->render('@' . Templates::PLATFORM_NAMESPACE . '/' . $template . '.html.twig', array_merge($this->project->scalars(), $data));
    }

    /**
     * The theme a request is drawn in and whether that is a preview; choosing it is also what points Twig's @theme at
     * it, so the two cannot disagree.
     *
     * @param array<string,mixed> $data
     * @return array{0:string,1:bool}
     */
    public function resolveTheme(array $data): array
    {
        [$themeKey, $previewActive] = $this->themes->resolve(self::text($_GET['theme_preview'] ?? ''), $this->project->mayPreview($data));
        $this->templates->use($this->themes->themeTemplateRoots($themeKey));

        return [$themeKey, $previewActive];
    }

    /**
     * Everything a page and its theme read, as a plain array: the theme's part, then the project's over it.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function viewModel(string $page, array $data, string $themeKey, bool $previewActive): array
    {
        return $this->project->model($page, $this->project->family($page), $data, $this->themeView($themeKey, $previewActive));
    }

    /**
     * The theme's part of every page's view model.
     *
     * @return array<string,mixed>
     */
    public function themeView(string $themeKey, bool $previewActive): array
    {
        $theme = $this->themes->theme($themeKey);
        $assetUrl = $this->themes->themeAssetUrl($themeKey);

        return [
            'theme_name' => self::text($theme['name']),
            // The theme's <html> carries it as data-bs-theme, so Bootstrap's light-dark() colours follow the theme.
            'color_scheme' => self::text($theme['color_scheme'] ?? 'light'),
            'theme_asset_url' => $assetUrl,
            'theme_preview_active' => $previewActive,
            'theme' => [
                'name' => self::text($theme['name']),
                'version' => self::text($theme['version']),
                'slug' => self::text($theme['slug']),
                'key' => $themeKey,
                'asset_url' => $assetUrl,
                'styles' => $this->themes->themeStyles($themeKey),
                'scripts' => $this->themes->themeScripts($themeKey),
                'external_styles' => $this->themes->externalStyles($themeKey),
                'external_scripts' => $this->themes->externalScripts($themeKey),
                'is_child' => (bool) $theme['is_child'],
                'parent_name' => self::text($theme['parent_name']),
                'parent_version' => self::text($theme['parent_version']),
            ],
        ];
    }

    /**
     * The page as it is sent: the generator tag first in the head, in place of any a theme wrote (first, so a truncated
     * page still carries it), then laid out. A page without a head is served as it is: the tag is not worth an outage.
     */
    public function served(string $html): string
    {
        $html = $this->withGenerator($html);
        if ($this->layout === null || ($this->debug && ($_SERVER['HTTP_X_SERVED_HTML'] ?? '') === 'as-built')) {
            return $html;
        }

        return $this->layout->document($html);
    }

    /**
     * Refuses data a template should never see: a name no template can use, or a value that is an object or a resource.
     *
     * @param array<array-key,mixed> $data
     */
    public static function assertData(array $data): void
    {
        foreach ($data as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key) !== 1) {
                throw new RuntimeException('Invalid template variable name: ' . $key);
            }
            self::assertValue($value, $key);
        }
    }

    private function withGenerator(string $html): string
    {
        $html = (string) preg_replace('/<meta\s[^>]*\bname="generator"[^>]*>/i', '', $html);
        if (preg_match('/<head\b[^>]*>/i', $html, $head, PREG_OFFSET_CAPTURE) !== 1) {
            return $html;
        }
        $after = $head[0][1] + strlen($head[0][0]);

        return substr($html, 0, $after) . '<meta name="generator" content="' . htmlspecialchars($this->project->generator(), ENT_QUOTES) . '">' . substr($html, $after);
    }

    private static function assertValue(mixed $value, string $path): void
    {
        if ($value === null || is_scalar($value)) return;
        if (is_array($value)) {
            foreach ($value as $key => $child) self::assertValue($child, $path . '[' . $key . ']');

            return;
        }
        throw new RuntimeException('Template data contains an object at ' . $path . '.');
    }

    private static function assertName(string $name, string $what): void
    {
        if (preg_match('/^[a-zA-Z0-9_\/-]+$/', $name) !== 1) {
            throw new RuntimeException('Invalid ' . $what . '.');
        }
    }

    private static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }
}
