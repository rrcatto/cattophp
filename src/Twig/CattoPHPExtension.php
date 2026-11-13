<?php

declare(strict_types=1);

namespace CattoPHP\Twig;

use CattoPHP\Http\ContentSecurityPolicy;
use CattoPHP\Http\FormToken;
use CattoPHP\Http\HtmlLayout;
use CattoPHP\Http\TypedForm;
use CattoPHP\Installation\Installation;
use CattoPHP\Runtime\SiteMode;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * What every template can use: the page's script nonce, the form token, what a refused form sent, `authored` for HTML
 * an author wrote (served byte for byte by HtmlLayout), times in the
 * installation's time zone, and under the one name `cattophp` the installation (`cattophp.installation`), demo or live
 * (`cattophp.site_mode`) and the layout CattoPHP's pages extend (`cattophp.layout`). A project's own function, filter or global of the same
 * name takes the place of CattoPHP's (this extension is added first).
 */
final class CattoPHPExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly ContentSecurityPolicy $policy,
        private readonly FormToken $tokens,
        private readonly Installation $installation,
        private readonly SiteMode $siteMode,
        private readonly RequestStack $requests,
        private readonly string $layout,
        private readonly HtmlLayout $htmlLayout,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', $this->policy->nonce(...)),
            new TwigFunction('form_token', $this->tokens->value(...)),
            new TwigFunction('typed_for', $this->typedFor(...)),
            new TwigFunction('typed_errors_for', $this->typedErrorsFor(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('local_time', $this->localTime(...)),
            // HTML written by an author, which the served page's layout serves byte for byte (HtmlLayout::asWritten).
            new TwigFilter('authored', $this->htmlLayout->asWritten(...), ['is_safe' => ['html']]),
        ];
    }

    public function getGlobals(): array
    {
        // One name of CattoPHP's own, so no variable a project's templates already use is taken.
        return ['cattophp' => ['layout' => $this->layout, 'installation' => $this->installation, 'site_mode' => $this->siteMode]];
    }

    /**
     * What a refused form sent to $action, for its fields to show again; nothing for any other form. The page drawn
     * takes the kept values (TypedForm::take).
     *
     * @return array<string,mixed>
     */
    public function typedFor(string $action): array
    {
        $request = $this->requests->getMainRequest();

        return $request === null ? [] : TypedForm::take($request, $action);
    }

    /**
     * Why each field of a refused form sent to $action was refused, by the field's name, for form.field's error beside
     * it; nothing for any other form (TypedForm::errors).
     *
     * @return array<string,string>
     */
    public function typedErrorsFor(string $action): array
    {
        $request = $this->requests->getMainRequest();

        return $request === null ? [] : TypedForm::errors($request, $action);
    }

    /** A moment in the installation's time zone, such as "9 November 2026, 14:05". */
    public function localTime(DateTimeInterface $at, string $format = 'j F Y, H:i'): string
    {
        return $this->installation->localTime($at)->format($format);
    }
}
