<?php

declare(strict_types=1);

namespace CattoPHP\Theme;

/**
 * A theme's own colour system, when it declares palettes of five colours: each palette ordered from darkest to lightest
 * by WCAG relative luminance, each colour given the foreground that reads on it (white or black, whichever contrasts
 * more), and one stylesheet setting the `--cl-color-1` to `--cl-color-5` tokens and the page's named colours from the
 * palette chosen. One palette is applied without a switcher; two or more make Core's switcher appear. A theme without
 * palettes keeps complete control of its colours.
 */
final class Palettes
{
    private function __construct()
    {
    }

    /**
     * The palette configuration of a theme as ThemeManager::theme() describes it.
     *
     * @param array<string,mixed> $theme
     * @return array<string,mixed>
     */
    public static function configuration(array $theme): array
    {
        $manifest = is_array($theme['manifest'] ?? null) ? $theme['manifest'] : [];
        $declared = $manifest['palettes'] ?? null;
        if (!is_array($declared) || count($declared) < 1) {
            return [
                'enabled' => false,
                'switcher_visible' => false,
                'palette_count' => 0,
                'theme_key' => self::text($theme['key'] ?? ''),
                'palettes' => [],
            ];
        }

        $palettes = [];
        foreach (array_values($declared) as $index => $palette) {
            if (!is_array($palette)) continue;
            $colours = array_values(array_map(static fn (mixed $colour): string => strtoupper(self::text($colour)), (array) ($palette['colors'] ?? [])));
            usort($colours, static fn (string $a, string $b): int => self::relativeLuminance($a) <=> self::relativeLuminance($b));
            $palettes[] = [
                'id' => 'palette-' . ($index + 1),
                'name' => trim(self::text($palette['name'] ?? '') !== '' ? self::text($palette['name']) : 'Palette ' . ($index + 1)),
                'colors' => $colours,
                'foregrounds' => array_map(self::contrastForeground(...), $colours),
            ];
        }

        $count = count($palettes);

        return [
            'enabled' => $count >= 1,
            'switcher_visible' => $count >= 2,
            'palette_count' => $count,
            'theme_key' => self::text($theme['key'] ?? ''),
            'theme_name' => self::text($theme['name'] ?? ''),
            'theme_version' => self::text($theme['version'] ?? ''),
            'palettes' => $palettes,
        ];
    }

    /**
     * The stylesheet a theme's palettes make (catto-palette.css in its published folder), or null for a theme without.
     *
     * @param array<string,mixed> $configuration From configuration().
     */
    public static function stylesheet(array $configuration): ?string
    {
        if (!(bool) ($configuration['enabled'] ?? false)) {
            return null;
        }
        $css = "/* Generated from the theme's palettes. Do not edit. */\n";
        foreach ((array) ($configuration['palettes'] ?? []) as $index => $palette) {
            $palette = is_array($palette) ? $palette : [];
            $colours = array_values((array) ($palette['colors'] ?? []));
            $foregrounds = array_values((array) ($palette['foregrounds'] ?? []));
            if (count($colours) !== 5 || count($foregrounds) !== 5) continue;
            $css .= 'body[data-cl-palette-managed="1"][data-cl-palette="' . $index . '"]{';
            for ($i = 0; $i < 5; $i++) {
                $css .= '--cl-color-' . ($i + 1) . ':' . self::text($colours[$i]) . ';--cl-on-color-' . ($i + 1) . ':' . self::text($foregrounds[$i]) . ';';
            }
            $css .= '--cl-darkest:var(--cl-color-1);--cl-dark:var(--cl-color-2);--cl-accent:var(--cl-color-3);--cl-light:var(--cl-color-4);--cl-lightest:var(--cl-color-5);';
            $css .= '--cl-page-bg:var(--cl-color-5);--cl-navbar-bg:var(--cl-color-1);--cl-sidebar-bg:var(--cl-color-1);--cl-large-block-bg:var(--cl-color-1);--cl-active-bg:var(--cl-color-2);--cl-button-bg:var(--cl-color-3);--cl-button-border:var(--cl-color-2);--cl-border:var(--cl-color-4);';
            // The page canvas is the slanted texture, so a palette re-colours its two bands rather than painting a flat
            // colour over it: the lighter band is the palette's page colour, the darker one that colour with a little of
            // the darkest, which keeps the texture tonal in every palette.
            $css .= '--cl-texture-light:var(--cl-color-5);--cl-texture-dark:color-mix(in srgb,var(--cl-color-5) 92%,var(--cl-color-1));';
            $css .= '--primary:var(--cl-color-1);--secondary:var(--cl-color-2);--accent:var(--cl-color-3);--highlight:var(--cl-color-3);--warm:var(--cl-color-4);--bg:var(--cl-color-5);--surface:var(--cl-color-5);--on-primary:var(--cl-on-color-1);--on-secondary:var(--cl-on-color-2);--on-accent:var(--cl-on-color-3);--on-highlight:var(--cl-on-color-3);--on-warm:var(--cl-on-color-4);--ink:var(--cl-color-1);--ink-2:var(--cl-color-2);';
            $css .= "}\n";
        }
        $css .= <<<'CSS'
body[data-cl-palette-managed="1"]{color:var(--cl-darkest)}
body[data-cl-palette-managed="1"] [data-theme-nav],body[data-cl-palette-managed="1"] .cl-sidebar,body[data-cl-palette-managed="1"] .cl-header{background:var(--cl-navbar-bg)!important;color:var(--cl-on-color-1)!important}
body[data-cl-palette-managed="1"] [data-theme-nav] summary,body[data-cl-palette-managed="1"] [data-theme-nav] a,body[data-cl-palette-managed="1"] [data-theme-nav] button,body[data-cl-palette-managed="1"] .cl-sidebar a,body[data-cl-palette-managed="1"] .cl-sidebar button,body[data-cl-palette-managed="1"] .cl-header a,body[data-cl-palette-managed="1"] .cl-header button{color:var(--cl-on-color-1)}
body[data-cl-palette-managed="1"] [data-nav-item].is-active:not(details),body[data-cl-palette-managed="1"] .cl-nav-link.is-active,body[data-cl-palette-managed="1"] .cl-navigation>a.is-active{background:var(--cl-active-bg)!important;color:var(--cl-on-color-2)!important;border-color:var(--cl-accent)!important;box-shadow:inset 3px 0 var(--cl-accent)}
body[data-cl-palette-managed="1"] [data-nav-item].is-active>summary{background:var(--cl-active-bg)!important;color:var(--cl-on-color-2)!important;border-color:var(--cl-accent)!important;box-shadow:inset 0 -3px var(--cl-accent)}
body[data-cl-palette-managed="1"] .hero,body[data-cl-palette-managed="1"] .cl-feature-band,body[data-cl-palette-managed="1"] [data-theme-block="dark"]{background-color:var(--cl-large-block-bg)!important;color:var(--cl-on-color-1)!important}
/* A dropdown panel is inside the navigation element but is not part of it.

   The rule above paints every link inside [data-theme-nav] with the colour that reads on the navigation bar, which for
   most palettes is white. A menu panel is a light surface drawn over the page, so its links would be white on white.

   The panel marks itself with cl-nav-panel and is given the palette's darkest colour, which reads on the light surface
   every floating panel uses; its links then inherit that rather than the bar's colour. Stating it on the panel is
   necessary rather than tidy: a theme that sets a white panel and no colour would otherwise inherit the bar's.

   The shared panel class also appears in sidebars. Scope the foreground to top headers so nested sidebar lists keep the
   rail's light-on-dark contrast. Links and buttons inherit their panel's foreground in either geometry.

   No !important, so a theme that wants a dark panel says so at this selector's weight. */
body[data-cl-palette-managed="1"] .cl-header .cl-nav-panel{color:var(--cl-darkest)}
body[data-cl-palette-managed="1"] .cl-nav-panel a,body[data-cl-palette-managed="1"] .cl-nav-panel button{color:inherit}
CSS;

        return self::alsoMatchPaletteClasses($css);
    }

    /**
     * Lets every palette rule match a class as well as the data attribute. The attribute is set by script after the page
     * has loaded, so the page would paint in the theme's own colours and repaint in the palette's a moment later; the
     * class is written by the server from the reader's stored choice, so the first paint is already right. Each selector
     * is rewritten on its own: the list rewritten as one string could make a rule that matches `body` alone.
     */
    private static function alsoMatchPaletteClasses(string $css): string
    {
        $rewrite = static function (string $selector): string {
            $class = (string) preg_replace(
                ['/body\[data-cl-palette-managed="1"\]/', '/\[data-cl-palette="(\d+)"\]/'],
                ['body.cl-palette-managed', '.cl-palette-$1'],
                $selector
            );

            return $class === $selector ? $selector : $selector . ',' . $class;
        };

        return (string) preg_replace_callback(
            '/^([^{}@\/][^{}]*)\{/m',
            static function (array $match) use ($rewrite): string {
                $out = [];
                foreach (array_map('trim', explode(',', $match[1])) as $selector) {
                    if ($selector !== '') $out[] = $rewrite($selector);
                }

                return implode(',', $out) . '{';
            },
            $css
        );
    }

    public static function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = [];
        foreach ([0, 2, 4] as $offset) {
            $value = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    public static function contrastForeground(string $background): string
    {
        $luminance = self::relativeLuminance($background);

        return 1.05 / ($luminance + 0.05) >= ($luminance + 0.05) / 0.05 ? '#FFFFFF' : '#000000';
    }

    private static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }
}
