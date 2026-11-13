<?php

declare(strict_types=1);

namespace CattoPHP\Ui;

/**
 * A project's own components beside CattoPHP's (the service tag `cattophp.ui_component_set`, given to every service
 * implementing this): what only that project draws, such as its catalogue's cards. Each is registered by name with its
 * template and its properties' defaults, as CattoPHP's own are in `Components::COMPONENTS`, and drawn the same way
 * (`ui()`, `ui_template()`, `ui_props()`). The registry refuses an unknown property, an object or rendered markup in
 * the properties and a missing required one (a null default); `check()` then checks what only the project knows.
 */
interface ComponentSet
{
    /**
     * The components by name, none of them one of CattoPHP's: each its full template name (such as
     * `@app/ui/catalogue/course-grid.html.twig`), its properties' defaults and, for the component gallery, the values
     * a property may take.
     *
     * @return array<string, array{template: string, defaults: array<string, mixed>, props?: array<string, list<mixed>>}>
     */
    public function components(): array;

    /**
     * The page's values its components read, handed to every component `ui()` draws (such as the form token for a
     * form inside a card), since a component is drawn in a context of its own.
     *
     * @return list<string>
     */
    public function sharedContext(): array;

    /**
     * One of its components' properties, already checked by the registry, checked as the project requires; returns
     * them, with anything the template needs worked out from them.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     * @throws \InvalidArgumentException when a value is not one the component takes
     */
    public function check(string $component, array $values): array;
}
