<?php

declare(strict_types=1);

namespace CattoPHP\Twig;

use CattoPHP\Http\ContentSecurityPolicy;
use CattoPHP\Http\FormToken;
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
 * What every template can use: the page's script nonce, the form token, what a refused form sent, the installation,
 * demo or live, and times in the installation's time zone.
 */
final class CattoPHPExtension extends AbstractExtension implements GlobalsInterface
{
    private const TYPED = '_cattophp_typed_values';

    public function __construct(
        private readonly ContentSecurityPolicy $policy,
        private readonly FormToken $tokens,
        private readonly Installation $installation,
        private readonly SiteMode $siteMode,
        private readonly RequestStack $requests,
        private readonly string $layout,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', $this->policy->nonce(...)),
            new TwigFunction('form_token', $this->tokens->value(...)),
            new TwigFunction('typed_for', $this->typedFor(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('local_time', $this->localTime(...)),
        ];
    }

    public function getGlobals(): array
    {
        return [
            'cattophp_layout' => $this->layout,
            'installation' => $this->installation,
            'site_mode' => $this->siteMode,
        ];
    }

    /**
     * What a refused form sent to $action, for its fields to show again; nothing for any other form.
     *
     * @return array<string,mixed>
     */
    public function typedFor(string $action): array
    {
        $request = $this->requests->getMainRequest();
        if ($request === null) {
            return [];
        }
        // Taken from the session once, then kept on the request, so every field of the form can read it.
        $taken = $request->attributes->get(self::TYPED);
        if (!is_array($taken)) {
            $taken = [];
        }
        if (!array_key_exists($action, $taken)) {
            $taken[$action] = TypedForm::take($request, $action);
            $request->attributes->set(self::TYPED, $taken);
        }

        return $taken[$action];
    }

    /** A moment in the installation's time zone, such as "9 November 2026, 14:05". */
    public function localTime(DateTimeInterface $at, string $format = 'j F Y, H:i'): string
    {
        return $this->installation->localTime($at)->format($format);
    }
}
