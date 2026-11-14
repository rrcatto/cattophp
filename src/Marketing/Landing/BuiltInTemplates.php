<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

/**
 * CattoPHP's own two templates, for any site: "Several offers" (the hero, the offers, questions and answers, a call to
 * action) and "A special with an end date" (the same with the countdown, and the promotion's price shown). No copy is
 * written for the author; the offers section takes the campaign's offers.
 */
final class BuiltInTemplates implements TemplateProvider
{
    public function templates(): iterable
    {
        yield new LandingTemplate('several-offers', 'Several offers', 'Hero, the offers, questions and answers, call to action.', 'both', [
            ['kind' => 'hero', 'data' => ['secondary' => 'offers']],
            ['kind' => 'offers'],
            ['kind' => 'faq'],
            ['kind' => 'cta'],
        ]);
        yield new LandingTemplate('special-with-end-date', 'A special with an end date', 'Adds the countdown and the promotion\'s price.', 'campaign', [
            ['kind' => 'hero', 'data' => ['secondary' => 'offers']],
            ['kind' => 'countdown', 'shown_when' => 'running'],
            ['kind' => 'offers', 'data' => ['show_promotion' => true]],
            ['kind' => 'faq'],
            ['kind' => 'cta'],
        ]);
    }
}
