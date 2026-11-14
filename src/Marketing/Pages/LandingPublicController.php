<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Pages;

use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\Journey;
use CattoPHP\Marketing\Landing\LandingPage;
use CattoPHP\Marketing\Landing\LandingPages;
use CattoPHP\Marketing\Landing\LandingPresses;
use CattoPHP\Marketing\Landing\LandingProject;
use CattoPHP\Marketing\Landing\LandingVersions;
use CattoPHP\Marketing\Landing\LandingViewBuilder;
use CattoPHP\Marketing\Landing\PictureSources;
use CattoPHP\Marketing\Landing\SectionKinds;
use CattoPHP\Marketing\MarketingProject;
use CattoPHP\Marketing\MarketingSettings;
use CattoPHP\Marketing\NoProject;
use CattoPHP\Marketing\Offer;
use CattoPHP\Marketing\Rows;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The landing pages' public addresses (config/routes/marketing_landing.php): an offer's page at /landing/{slug}, and under
 * it and under a campaign's own address (/c/{campaign}, whose page TrackingLinkController draws) a page's pictures
 * (/picture/{ref}) and its GET buttons (/go/{press}).
 *
 * - An offer's page is drawn while it is published and its offer is public, else it is the site's ordinary not found. Its
 *   visit is recorded (Marketing\Journey: a person's only), never its cookie: only tracking links give one.
 * - A picture is served only when the page names it now (its shown sections, its share picture), to anyone while the page
 *   is public (cached publicly for MARKETING_LANDING_PICTURE_CACHED), and to those who may see its editor before then, or
 *   when a hidden section or a kept version names it (private, never stored); otherwise not found. It is sent as its own
 *   type, inline, with nosniff and a sandboxing policy of its own.
 * - A press records itself and forwards (302) to what the page's section holds for it, worked out again from the page
 *   as the reader sees it now, never to anything the address names; an unknown press is not found.
 */
final class LandingPublicController
{
    public function __construct(
        private readonly LandingPages $pages,
        private readonly LandingViewBuilder $builder,
        private readonly Journey $journey,
        private readonly LandingPresses $presses,
        private readonly Campaigns $campaigns,
        private readonly LandingProject $landing,
        private readonly LandingVersions $versions,
        private readonly SectionKinds $kinds,
        private readonly MarketingSettings $settings,
        private readonly MarketingProject $project = new NoProject(),
        private readonly ?PictureSources $pictures = null,
        private readonly ?TokenStorageInterface $tokens = null,
    ) {
    }

    /** An offer's page, as visitors see it now. */
    public function page(Request $request, string $slug): Response
    {
        $page = $this->pages->bySlug($slug);
        $view = $page === null || !$page->isOfferPage() ? null : $this->builder->forVisitor($page, $this->journey->account());
        if ($view === null) {
            throw new NotFoundHttpException('There is no such landing page.');
        }
        $this->journey->opened($view, $request);
        $response = new Response($this->builder->draw($view));
        // The buttons are the reader's own, and each visit is counted: never kept by a shared cache.
        $response->headers->set('Cache-Control', 'private, no-cache');

        return $response;
    }

    /** A picture a page names, at {page address}/picture/{ref}. */
    public function picture(Request $request, string $ref, ?string $slug = null, ?string $campaign = null): Response
    {
        $page = $this->find($slug, $campaign);
        $public = $this->presses->isPublicNow($page);
        if (!$public && !$this->landing->access($page, $this->tokens?->getToken()?->getUser())->see) {
            throw new NotFoundHttpException('There is no such picture.');
        }
        $context = $this->pages->context($page);
        $named = null;
        foreach ($public ? $this->pages->pictures($page, $context) : $this->everyPicture($page) as $picture) {
            if ($picture['ref'] === $ref && $picture['ref'] !== '') {
                $named = $picture;
                break;
            }
        }
        $resolved = $named === null ? null : $this->pictures?->resolve($named, $context);
        if ($resolved === null || !$resolved->isFile() || $resolved->path === null || !is_file($resolved->path)) {
            throw new NotFoundHttpException('There is no such picture.');
        }
        $response = new BinaryFileResponse($resolved->path, Response::HTTP_OK, [], $public, null, false, false);
        $response->headers->set('Content-Type', (string) $resolved->mimeType);
        // Sent with its own file name, a name in plain letters beside it for a browser that reads no other.
        $name = (string) $resolved->fileName;
        $plain = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name), '-');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $name !== '' ? $name : 'picture', $plain !== '' ? $plain : 'picture');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        if ($public) {
            $response->headers->set('Cache-Control', 'public, max-age=' . $this->settings->landingPictureCached()->lengthInSeconds());
        } else {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }

    /** A GET button pressed, at {page address}/go/{press}: recorded, then on to what the section holds for it. */
    public function press(Request $request, string $press, ?string $slug = null, ?string $campaign = null): Response
    {
        $page = $this->find($slug, $campaign);
        $view = preg_match(LandingViewBuilder::PRESS, $press) === 1 ? $this->builder->forVisitor($page, $this->journey->account()) : null;
        $target = $view === null ? null : $this->builder->press($view, $press);
        if ($view === null || $target === null || $target['href'] === '') {
            throw new NotFoundHttpException('There is no such button.');
        }
        $this->presses->followed($request, $page, $target['section'], $this->offer($view->context->offers, $target['offer']), $target['action']);
        $response = new RedirectResponse($target['href'], Response::HTTP_FOUND);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /** The page an address names: an offer's by its slug, or a campaign's by the campaign's code; else not found. */
    private function find(?string $slug, ?string $campaign): LandingPage
    {
        $page = null;
        if ($slug !== null) {
            $page = $this->pages->bySlug($slug);
            $page = $page !== null && $page->isOfferPage() ? $page : null;
        } elseif ($campaign !== null) {
            $found = $this->campaigns->get(strtolower($campaign));
            $page = $found === null ? null : $this->pages->forCampaign($found);
        }

        return $page ?? throw new NotFoundHttpException('There is no such landing page.');
    }

    /**
     * Every picture a page's editors may see: its share picture, every section's (shown or hidden), and every kept
     * version's.
     *
     * @return list<array{source:string,ref:string}>
     */
    private function everyPicture(LandingPage $page): array
    {
        $pictures = $page->sharePicture === null ? [] : [$page->sharePicture];
        foreach ($this->pages->sections($page) as $section) {
            if ($section->kind !== null) {
                $pictures = [...$pictures, ...$section->kind->pictures($section->data)];
            }
        }
        foreach ($this->versions->list($page) as $version) {
            $share = LandingPages::picture(is_array($version->settings()['share_picture'] ?? null) ? $version->settings()['share_picture'] : null);
            if ($share !== null) {
                $pictures[] = $share;
            }
            foreach ($version->sections() as $section) {
                $kind = $this->kinds->get(Rows::text($section['kind'] ?? ''));
                if ($kind !== null && is_array($section['data'] ?? null)) {
                    $pictures = [...$pictures, ...$kind->pictures($section['data'])];
                }
            }
        }

        return $pictures;
    }

    /**
     * The offer a press was about, from the page's or the project's offers.
     *
     * @param list<Offer> $offers
     */
    private function offer(array $offers, ?string $key): ?Offer
    {
        if ($key === null) {
            return null;
        }
        foreach ($offers as $offer) {
            if ($offer->key() === $key) {
                return $offer;
            }
        }
        $parts = explode(':', $key, 2);

        return count($parts) === 2 ? $this->project->offer($parts[0], $parts[1]) : null;
    }
}
