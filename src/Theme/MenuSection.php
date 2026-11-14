<?php

declare(strict_types=1);

namespace CattoPHP\Theme;

use InvalidArgumentException;

/**
 * One drop-down of a project's menu, such as "Administration": its key (a slug, so two providers may add to the same
 * section), its label, the name of its icon in the project's sprite, and its entries in order. A provider whose section
 * has the key of one given earlier adds its entries to that one, whose label and icon stand.
 */
final class MenuSection
{
    /** A section's key and an icon's name: lower-case letters, digits and hyphens, starting with a letter. */
    public const NAME = '/^[a-z][a-z0-9-]*$/';

    /** @var list<MenuEntry> */
    public readonly array $entries;

    /**
     * @param string $icon The name of an icon in the project's sprite, or '' for none.
     * @param iterable<MenuEntry> $entries
     */
    public function __construct(public readonly string $key, public readonly string $label, public readonly string $icon, iterable $entries)
    {
        if (preg_match(self::NAME, $key) !== 1) {
            throw new InvalidArgumentException(sprintf('A menu section\'s key is lower-case letters, digits and hyphens: "%s" is not.', $key));
        }
        if (trim($label) === '') {
            throw new InvalidArgumentException('Menu section ' . $key . ' needs a label in words.');
        }
        if ($icon !== '' && preg_match(self::NAME, $icon) !== 1) {
            throw new InvalidArgumentException(sprintf('Menu section %s names an icon as the sprite does, such as nav-settings: "%s" is not.', $key, $icon));
        }
        $list = [];
        foreach ($entries as $entry) {
            $list[] = $entry;
        }
        $this->entries = $list;
    }
}
