<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

/**
 * A section kind a button may lead to on the page (the hero's second button, a call to action), which says what such a
 * button says when its author gives it no words: "Questions and answers", "See the offers". A kind without it is still a
 * place to lead to, named by its label.
 */
interface JumpTarget
{
    public function jumpLabel(): string;
}
