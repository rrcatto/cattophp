<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

/**
 * Built-in landing page templates, found by the tag cattophp.marketing_landing_templates (ServiceTags::LANDING_TEMPLATES;
 * autoconfigured). CattoPHP's own (BuiltInTemplates) is switched off by cattophp.marketing.landing_builtin_templates:
 * false, for a project that gives its own.
 */
interface TemplateProvider
{
    /** @return iterable<LandingTemplate> */
    public function templates(): iterable;
}
