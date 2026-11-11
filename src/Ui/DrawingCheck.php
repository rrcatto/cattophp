<?php

declare(strict_types=1);

namespace CattoPHP\Ui;

/**
 * A drawing a project allows as a feature card's picture (the component form.picture-choice, an option's `drawing`):
 * markup the project itself made, such as a thumbnail of a slide, which only the project can recognise as its own and
 * inert (the service tag `cattophp.ui_drawing`, given to every service implementing this). Without one, a card with a
 * drawing is refused.
 */
interface DrawingCheck
{
    public const TAG = 'cattophp.ui_drawing';

    /** Whether the markup is one of the project's own drawings, with nothing in it that runs. */
    public function allows(string $drawing): bool;
}
