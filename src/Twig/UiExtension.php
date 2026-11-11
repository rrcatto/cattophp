<?php

declare(strict_types=1);

namespace CattoPHP\Twig;

use CattoPHP\Foundation\AccountReference;
use CattoPHP\Ui\Components;
use CattoPHP\Ui\IconLibrary;
use InvalidArgumentException;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The component registry in templates: `ui('name', {…})` draws a component, `ui_template('name')` and
 * `ui_props('name', {…})` embed one to fill its slots, and `ui_field_attrs(props)` gives a form field's control its
 * relationships to the field's help and error; `icon_sprite()` is the sprite's address and `lucide('name')` draws an icon from
 * the library. `account_reference` shows an account reference as people read it
 * (CL-20261016-0001-K), as data.person does, and `share` a percentage as a bar's length (feedback.progress).
 */
final class UiExtension extends AbstractExtension
{
    public function __construct(private readonly Components $components, private readonly IconLibrary $icons = new IconLibrary())
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('ui_template', $this->components->template(...)),
            new TwigFunction('ui_field_attrs', $this->components->fieldAttributes(...)),
            new TwigFunction('ui_props', $this->components->properties(...)),
            new TwigFunction('ui', $this->components->render(...), ['needs_environment' => true, 'needs_context' => true]),
            // The icon sprite's fingerprinted address, for a drawing that names a symbol itself.
            new TwigFunction('icon_sprite', $this->components->iconSprite(...)),
            // An icon from the icon library by its name, drawn inline (a feature card's icon).
            new TwigFunction('lucide', fn (string $name): string => $this->icons->svg($name) ?? throw new InvalidArgumentException('The icon library has no ' . $name . '.')),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('account_reference', static fn (?string $value): string => AccountReference::format((string) $value)),
            new TwigFilter('share', self::share(...)),
        ];
    }

    /**
     * A bar's length as classes, for a percentage: "cl-share cl-share-37", each given its length by the stylesheet, so no
     * inline style is needed under a strict content policy. Whole percent; anything above nothing is at least 1, so a
     * small share still shows; never over 100.
     */
    public static function share(mixed $percent): string
    {
        $value = is_numeric($percent) ? (float) $percent : 0.0;
        $whole = $value <= 0 ? 0 : max(1, min(100, (int) round($value)));

        return 'cl-share cl-share-' . $whole;
    }
}
