<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Pages;

use CattoPHP\Activity\Actor;
use CattoPHP\Admin\AccountNames;
use CattoPHP\Http\StockPages;
use CattoPHP\Http\TypedForm;
use CattoPHP\Marketing\Landing\CardOptions;
use CattoPHP\Marketing\Landing\JumpTarget;
use CattoPHP\Marketing\Landing\LandingAccess;
use CattoPHP\Marketing\Landing\LandingContext;
use CattoPHP\Marketing\Landing\LandingPage;
use CattoPHP\Marketing\Landing\LandingPages;
use CattoPHP\Marketing\Landing\LandingProject;
use CattoPHP\Marketing\Landing\LandingSection;
use CattoPHP\Marketing\Landing\LandingSections;
use CattoPHP\Marketing\Landing\LandingSettingsInput;
use CattoPHP\Marketing\Landing\LandingTemplate;
use CattoPHP\Marketing\Landing\LandingTemplates;
use CattoPHP\Marketing\Landing\LandingVersion;
use CattoPHP\Marketing\Landing\LandingVersions;
use CattoPHP\Marketing\Landing\LandingView;
use CattoPHP\Marketing\Landing\LandingViewBuilder;
use CattoPHP\Marketing\Landing\LandingVisit;
use CattoPHP\Marketing\Landing\NotRunning;
use CattoPHP\Marketing\Landing\OfferCard;
use CattoPHP\Marketing\Landing\PictureSource;
use CattoPHP\Marketing\Landing\PictureSources;
use CattoPHP\Marketing\Landing\SectionKind;
use CattoPHP\Marketing\Landing\SectionKinds;
use CattoPHP\Marketing\MarketingPermissions;
use CattoPHP\Marketing\MarketingProject;
use CattoPHP\Marketing\MarketingRefused;
use CattoPHP\Ui\HalfRows;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

/**
 * A landing page's editor, one page holding everything about it, wherever it is mounted (an EditorPlace): its head (the
 * title, its status, what it is for and its address as badges, with View or Preview, Publish or Unpublish and Delete),
 * what stops it being published, its sections (the hero fixed at the top, the others put in order by drag, keyboard or
 * menu, each with its width, Edit, Show or Hide and Remove; Add a section as feature cards; a small drawing of the page),
 * the open section's form and its preview, starting again from a template and saving the page as one, the page's
 * settings and its earlier versions. Every action is a form that works without JavaScript and answers with a redirect
 * and a notice; with htmx the part of the page it changes comes back in place (the list, the open section, the
 * settings), with what else it changed as htmx partials, and a refusal comes back as typed with the reason (422).
 *
 * CattoPHP's own routes mount it at /marketing/landing-pages/{reference} (LandingEditorController); a project mounts it
 * under its own address with routes(), answering what its reader may do itself.
 */
final class LandingEditor
{
    /** Each route of the editor: its name's ending, its method and its path after the editor's own. */
    public const ROUTES = [
        'edit' => ['GET', ''],
        'make' => ['POST', ''],
        'preview' => ['GET', '/preview'],
        'version' => ['GET', '/versions/{version}'],
        'version_use' => ['POST', '/versions/{version}/use'],
        'settings' => ['POST', '/settings'],
        'publish' => ['POST', '/publish'],
        'unpublish' => ['POST', '/unpublish'],
        'delete' => ['POST', '/delete'],
        'template_save' => ['POST', '/save-as-template'],
        'start_again' => ['POST', '/start-again'],
        'section_add' => ['POST', '/sections'],
        'section_save' => ['POST', '/sections/{section}'],
        'section_show' => ['POST', '/sections/{section}/show'],
        'section_hide' => ['POST', '/sections/{section}/hide'],
        'section_width' => ['POST', '/sections/{section}/width'],
        'section_shown_when' => ['POST', '/sections/{section}/shown-when'],
        'section_move' => ['POST', '/sections/{section}/move'],
        'section_remove' => ['POST', '/sections/{section}/remove'],
    ];

    /** A section's reference and a version's number as they are in an address: v3. */
    public const SECTION = '[a-z0-9]{8}';
    public const VERSION = 'v[1-9][0-9]{0,5}';

    /** The icon of each picture source's card; a project's own source shows pictures. */
    private const SOURCE_ICONS = ['upload' => 'image-up', 'offer' => 'image', 'none' => 'image-off'];

    /** What a section's Shown choice says on its card. */
    private const SHOWN_WHEN_NOTES = ['always' => 'Whatever the campaign is doing', 'running' => 'Left out before it starts, while paused and after it ends', 'not_running' => 'Only before it starts, while paused and after it ends'];

    public function __construct(
        private readonly StockPages $stock,
        private readonly MarketingPages $marketing,
        private readonly LandingPages $pages,
        private readonly LandingSections $sections,
        private readonly LandingVersions $versions,
        private readonly LandingTemplates $templates,
        private readonly SectionKinds $kinds,
        private readonly PictureSources $pictureSources,
        private readonly MarketingProject $project,
        private readonly AccountNames $names,
        private readonly Environment $twig,
        private readonly ?LandingViewBuilder $views = null,
        private readonly ?LandingProject $landing = null,
    ) {
    }

    /**
     * Adds the editor's routes under a path, each named `<name>_<ending>` (ROUTES) and sent to one controller method,
     * which is given the ending as its argument $action and answers with answer(). $make adds the POST that makes the page,
     * for an editor mounted for an offer that may have none yet.
     *
     * @param string|array{0:class-string,1:string} $controller
     * @param array<string,string> $requirements the path's own parameters' requirements
     */
    public static function routes(RoutingConfigurator $routes, string $name, string $path, string|array $controller, array $requirements = [], bool $make = false): void
    {
        foreach (self::ROUTES as $action => [$method, $ending]) {
            if ($action === 'make' && !$make) {
                continue;
            }
            $routes->add($name . '_' . $action, $path . $ending)->controller($controller)->methods([$method])->defaults(['action' => $action])
                ->requirements($requirements + (str_contains($ending, '{section}') ? ['section' => self::SECTION] : []) + (str_contains($ending, '{version}') ? ['version' => self::VERSION] : []));
        }
    }

    /** What the place's reader may do with a campaign's page, from the marketing permissions. */
    public function campaignAccess(): LandingAccess
    {
        $manage = $this->stock->may(MarketingPermissions::MANAGE);

        return new LandingAccess($manage || $this->stock->may(MarketingPermissions::VIEW), $manage, $manage, $manage, $manage);
    }

    /** Answers one of the editor's routes (ROUTES) for a place. */
    public function answer(Request $request, EditorPlace $place, string $action): Response
    {
        if (!$place->access->see) {
            throw new AccessDeniedException('This landing page is not one you may see.');
        }
        if ($place->page === null) {
            return match ($action) {
                'edit' => $this->empty($request, $place),
                'make' => $this->make($request, $place),
                default => throw new NotFoundHttpException('There is no landing page here yet.'),
            };
        }
        $section = MarketingPages::text($request->attributes->get('section', ''));
        $version = MarketingPages::text($request->attributes->get('version', ''));

        return match ($action) {
            'edit' => $this->edit($request, $place),
            'make' => $this->refusedPost($request, $place, 'This ' . $this->offerWord($place) . ' already has a landing page.'),
            'preview' => $this->preview($request, $place),
            'version' => $this->version($request, $place, self::versionNumber($version)),
            'version_use' => $this->useVersion($request, $place, self::versionNumber($version)),
            'settings' => $this->saveSettings($request, $place),
            'publish' => $this->publish($request, $place),
            'unpublish' => $this->unpublish($request, $place),
            'delete' => $this->delete($request, $place),
            'template_save' => $this->saveTemplate($request, $place),
            'start_again' => $this->startAgain($request, $place),
            'section_add' => $this->addSection($request, $place),
            'section_save' => $this->saveSection($request, $place, $section),
            'section_show', 'section_hide', 'section_width', 'section_shown_when', 'section_remove', 'section_move' => $this->rowAction($request, $place, $action, $section),
            default => throw new NotFoundHttpException('There is no such page.'),
        };
    }

    private function edit(Request $request, EditorPlace $place): Response
    {
        $page = $this->page($place);
        $open = $this->openSection($request, $place, $page);
        $model = $this->model($request, $place, $page, $open, true);
        if ($request->headers->has('HX-Request') && $open !== '') {
            return $this->fragment('pane', [], $model);
        }

        return $this->stock->page($request, 'marketing-landing-edit', '@CattoPHP/marketing/landing-edit.html.twig', $model);
    }

    /** An offer with no page yet: what a page would be, and Make the landing page with a template chosen. */
    private function empty(Request $request, EditorPlace $place): Response
    {
        $offer = $this->project->offer((string) $place->offerType, (string) $place->offerReference) ?? throw new NotFoundHttpException('There is no such offer.');
        try {
            $makeAction = $this->stock->path($place->routeName('make'), $place->parameters);
        } catch (RoutingException) {
            // Mounted without the post that makes a page (routes() without $make): there is nothing here yet.
            throw new NotFoundHttpException('There is no landing page here yet.');
        }
        $context = $this->pages->contextFor(null, $offer);
        $errors = $this->stock->errors($request);
        $typed = TypedForm::take($request, $makeAction);
        $templates = $this->templates->available($context, $place->access->savedTemplates);

        return $this->stock->page($request, 'marketing-landing-edit', '@CattoPHP/marketing/landing-edit.html.twig', [
            'title' => $offer->label,
            'lead' => 'A page that sells this ' . $context->offerWord . ', separate from its own page on the site. Its price and pictures come from the ' . $context->offerWord . ' each time the page is shown.',
            'kicker' => $place->kicker,
            'empty' => true,
            'may_edit' => $place->access->edit,
            'back' => ['href' => $place->backHref, 'label' => $place->backLabel],
            'make_action' => $makeAction,
            'template_cards' => $this->templateCards($templates),
            'template_chosen' => MarketingPages::text($typed['template'] ?? ($templates[0]->key ?? '')),
            'errors' => $errors + ['template' => ''],
            'offer_word' => $context->offerWord,
        ]);
    }

    private function make(Request $request, EditorPlace $place): Response
    {
        $this->need($place, 'edit');
        if (!$this->stock->tokenIsValid($request)) {
            return $this->stock->expired($request, $place->routeName('edit'), $place->parameters);
        }
        try {
            $result = $this->pages->createForOffer((string) $place->offerType, (string) $place->offerReference, MarketingPages::text($request->request->get('template', '')), $this->stock->actor());
        } catch (MarketingRefused $refused) {
            return $this->stock->refused($request, CampaignsController::why($refused, 'The landing page was not made'), $place->routeName('edit'), $place->parameters, errors: $refused);
        }

        return $this->stock->done($request, 'The landing page is made as a draft at ' . $this->pages->address($result->page) . '. Write its copy, then publish it.' . self::skipped($result->skipped),
            $place->routeName('edit'), $place->parameters);
    }

    private function preview(Request $request, EditorPlace $place): Response
    {
        $page = $this->page($place);

        return $this->drawn($this->views()->preview($page, $this->stock->path($place->routeName('edit'), $place->parameters), $this->account()));
    }

    private function version(Request $request, EditorPlace $place, int $number): Response
    {
        $page = $this->page($place);
        $version = $this->versions->get($page, $number);
        if ($version === null || !$version->isPublished()) {
            throw new NotFoundHttpException('There is no such version of this page.');
        }

        $view = $this->views()->version($page, $version, $this->stock->path($place->routeName('edit'), $place->parameters) . '#versions', $this->account())
            ?? throw new NotFoundHttpException('There is no such version of this page.');

        return $this->drawn($view);
    }

    private function useVersion(Request $request, EditorPlace $place, int $number): Response
    {
        return $this->act($request, $place, 'edit', function (LandingPage $page) use ($number): string {
            $this->sections->useVersion($page->reference, $number, $this->stock->actor());

            return sprintf('Version %d is on the page again.', $number) . ($page->isPublished() ? ' Visitors see it now, and it is kept as a new version.' : '');
        }, 'versions');
    }

    private function publish(Request $request, EditorPlace $place): Response
    {
        return $this->act($request, $place, 'publish', fn (LandingPage $page): string => 'The page is published: anyone can open it at ' . $this->pages->address($this->pages->publish($page->reference, $this->stock->actor())) . '.');
    }

    private function unpublish(Request $request, EditorPlace $place): Response
    {
        return $this->act($request, $place, 'publish', function (LandingPage $page): string {
            $this->pages->unpublish($page->reference, $this->stock->actor());

            return $page->isCampaignPage()
                ? 'The page is unpublished. Everything on it is kept, and the campaign\'s links lead where they did before it had a page.'
                : 'The page is unpublished. Everything on it is kept, and its address is not found until you publish it again.';
        });
    }

    private function delete(Request $request, EditorPlace $place): Response
    {
        $this->need($place, 'edit');
        $page = $this->page($place);
        if (!$this->stock->tokenIsValid($request)) {
            return $this->stock->expired($request, $place->routeName('edit'), $place->parameters);
        }
        try {
            $this->pages->delete($page->reference, $this->stock->actor());
        } catch (MarketingRefused $refused) {
            return $this->stock->refused($request, $refused->getMessage(), $place->routeName('edit'), $place->parameters);
        }
        $this->stock->say($request, 'cattophp_done', 'The landing page is deleted, with everything written on it.');
        if ($place->backHref !== '') {
            return new RedirectResponse($place->backHref, Response::HTTP_SEE_OTHER);
        }

        return $this->stock->onTo($place->routeName('edit'), $place->parameters);
    }

    private function saveTemplate(Request $request, EditorPlace $place): Response
    {
        $this->need($place, 'savedTemplates');

        return $this->act($request, $place, 'edit', fn (LandingPage $page): string => 'The page is saved as the template ' . $this->pages->saveAsTemplate($page->reference,
            MarketingPages::text($request->request->get('name', '')), MarketingPages::text($request->request->get('note', '')), $this->stock->actor())->name
            . '. It is offered whenever a landing page is made or started again.', 'templates', true);
    }

    private function startAgain(Request $request, EditorPlace $place): Response
    {
        return $this->act($request, $place, 'edit', function (LandingPage $page) use ($request, $place): string {
            $key = MarketingPages::text($request->request->get('template', ''));
            $result = $this->sections->startAgain($page->reference, $key, $place->access->savedTemplates, $this->stock->actor());
            $name = $this->templates->get($key)->name ?? $key;

            return 'The page is started again from the template ' . $name . '.' . ($page->isPublished() ? ' What it showed before is kept in its earlier versions.' : '') . self::skipped($result->skipped);
        }, 'templates');
    }

    private function saveSettings(Request $request, EditorPlace $place): Response
    {
        $this->need($place, 'edit');
        $page = $this->page($place);
        $htmx = $request->headers->has('HX-Request');
        if (!$this->stock->tokenIsValid($request)) {
            return $htmx ? $this->fragment('settings', ['checks', 'publish'], $this->model($request, $place, $page, '', false, settingsNotice: ['danger', 'That form had run out, so nothing was changed. Reload the page and try again.']), Response::HTTP_FORBIDDEN)
                : $this->stock->expired($request, $place->routeName('edit'), $place->parameters, 'settings');
        }
        $posted = $request->request->all();
        try {
            $context = $this->pages->context($page);
            $share = $this->pictureInput(['share_picture' => $posted['share_picture'] ?? null], $request, $place, $context)['share_picture'];
            $saved = $this->pages->saveSettings($page->reference, new LandingSettingsInput(
                MarketingPages::text($posted['slug'] ?? ''),
                MarketingPages::text($posted['meta_title'] ?? ''),
                MarketingPages::text($posted['meta_description'] ?? ''),
                is_array($share) ? $share : null,
                MarketingPages::text($posted['not_running'] ?? NotRunning::KEEP),
                MarketingPages::text($posted['not_running_to'] ?? ''),
            ), $this->stock->actor());
        } catch (MarketingRefused $refused) {
            if ($htmx) {
                return $this->fragment('settings', [], $this->model($request, $place, $page, '', false, settingsTyped: $posted, settingsErrors: $refused->errors(),
                    settingsNotice: ['danger', CampaignsController::why($refused, 'The settings were not saved')]), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->stock->refused($request, CampaignsController::why($refused, 'The settings were not saved'), $place->routeName('edit'), $place->parameters, 'settings', errors: $refused);
        }
        if ($htmx) {
            return $this->fragment('settings', ['checks', 'publish', 'address'], $this->model($request, $place->withPage($saved), $saved, '', false, settingsNotice: ['success', 'The page\'s settings are saved.']));
        }

        return $this->stock->done($request, 'The page\'s settings are saved.', $place->routeName('edit'), $place->parameters, 'settings');
    }

    private function addSection(Request $request, EditorPlace $place): Response
    {
        $this->need($place, 'edit');
        $page = $this->page($place);
        $htmx = $request->headers->has('HX-Request');
        if (!$this->stock->tokenIsValid($request)) {
            return $htmx ? $this->fragment('pane', [], $this->model($request, $place, $page, '', false, paneNotice: ['danger', 'That form had run out, so nothing was changed. Reload the page and try again.']), Response::HTTP_FORBIDDEN)
                : $this->stock->expired($request, $place->routeName('edit'), $place->parameters, 'sections');
        }
        try {
            $section = $this->sections->add($page->reference, MarketingPages::text($request->request->get('kind', '')), $this->stock->actor());
        } catch (MarketingRefused $refused) {
            if ($htmx) {
                return $this->fragment('pane', ['add'], $this->model($request, $place, $page, '', false, paneNotice: ['danger', 'The section was not added. ' . self::firstReason($refused)]), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->stock->refused($request, 'The section was not added. ' . self::firstReason($refused), $place->routeName('edit'), $place->parameters, 'sections');
        }
        $message = 'A ' . mb_strtolower($section->label()) . ' section is added at the bottom' . ($section->isHero() ? ', or rather the top, where the hero always is' : '') . ', hidden until you save it.';
        if ($htmx) {
            $page = $this->pages->require($page->reference);
            $response = $this->fragment('pane', ['list', 'checks', 'publish', 'add'], $this->model($request, $place->withPage($page), $page, $section->reference, false, paneNotice: ['success', $message]));
            $response->headers->set('HX-Push-Url', $this->stock->path($place->routeName('edit'), $place->parameters + ['section' => $section->reference]));

            return $response;
        }

        return $this->stock->done($request, $message, $place->routeName('edit'), $place->parameters + ['section' => $section->reference], 'landing-edit');
    }

    private function saveSection(Request $request, EditorPlace $place, string $reference): Response
    {
        $this->need($place, 'edit');
        $page = $this->page($place);
        $section = $this->sections->get($page, $reference) ?? throw new NotFoundHttpException('That section is not on this page.');
        $htmx = $request->headers->has('HX-Request');
        $back = $place->parameters + ['section' => $section->reference];
        if (!$this->stock->tokenIsValid($request)) {
            return $htmx ? $this->fragment('pane', [], $this->model($request, $place, $page, $section->reference, false, typed: $request->request->all(),
                paneNotice: ['danger', 'That form had run out, so nothing was changed. Save it again.']), Response::HTTP_FORBIDDEN)
                : $this->stock->expired($request, $place->routeName('edit'), $back, 'landing-edit');
        }
        $posted = $request->request->all();
        try {
            $input = $this->pictureInput(array_diff_key($posted, ['_token' => true, 'upload_title' => true]), $request, $place, $this->pages->context($page));
            $saved = $this->sections->save($page->reference, $section->reference, $input, $this->stock->actor());
        } catch (MarketingRefused $refused) {
            $why = CampaignsController::why($refused, 'The section was not saved');
            if ($htmx) {
                return $this->fragment('pane', [], $this->model($request, $place, $page, $section->reference, false, typed: $posted, errors: $refused->errors(), paneNotice: ['danger', $why]),
                    Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->stock->refused($request, $why, $place->routeName('edit'), $back, 'landing-edit', errors: $refused);
        }
        $message = 'The ' . mb_strtolower($saved->label()) . ' section is saved' . ($saved->enabled ? '' : ', and stays hidden until you show it') . '.';
        if ($htmx) {
            $page = $this->pages->require($page->reference);

            return $this->fragment('pane', ['list', 'checks', 'publish', 'add'], $this->model($request, $place->withPage($page), $page, $saved->reference, false, paneNotice: ['success', $message]));
        }

        return $this->stock->done($request, $message, $place->routeName('edit'), $back, 'landing-edit');
    }

    /**
     * Show, hide, width, shown when, move and remove: with htmx the list comes back (the stored list with why, 409, when
     * a move was made from an order that is out of date), with the checks, the publish button, the add cards and, when
     * it changed, the open section.
     */
    private function rowAction(Request $request, EditorPlace $place, string $action, string $reference): Response
    {
        $this->need($place, 'edit');
        $page = $this->page($place);
        $section = $this->sections->get($page, $reference) ?? throw new NotFoundHttpException('That section is not on this page.');
        $htmx = $request->headers->has('HX-Request');
        $open = MarketingPages::text($request->request->get('open', ''));
        $open = $this->sections->get($page, $open) !== null ? $open : '';
        $back = $place->parameters + ($open !== '' ? ['section' => $open] : []);
        if (!$this->stock->tokenIsValid($request)) {
            return $htmx ? $this->fragment('list', [], $this->model($request, $place, $page, $open, false, listError: 'That form had run out, so nothing was changed. Reload the page and try again.'), Response::HTTP_FORBIDDEN)
                : $this->stock->expired($request, $place->routeName('edit'), $back, 'sections');
        }
        $actor = $this->stock->actor();
        $label = $section->label();
        // A move refused because the list shown was out of date is a conflict, as the sortable list expects.
        $refusedStatus = $action === 'section_move' ? Response::HTTP_CONFLICT : Response::HTTP_UNPROCESSABLE_ENTITY;
        try {
            $message = match ($action) {
                'section_show' => $this->sections->show($page->reference, $section->reference, $actor)->label() . ' is shown on the page.',
                'section_hide' => $this->sections->hide($page->reference, $section->reference, $actor)->label() . ' is hidden: kept, and left off the page.',
                'section_width' => $label . ' is now ' . ($this->sections->width($page->reference, $section->reference, MarketingPages::text($request->request->get('width', '')), $actor)->width === 'half' ? 'half' : 'the full') . ' width.',
                'section_shown_when' => $label . ' is shown ' . mb_strtolower(LandingSection::SHOWN_WHEN[$this->sections->shownWhen($page->reference, $section->reference, MarketingPages::text($request->request->get('shown_when', '')), $actor)->shownWhen] ?? 'always') . '.',
                'section_move' => $this->move($request, $page, $section, $actor),
                default => $this->removeSection($page, $section, $actor),
            };
        } catch (MarketingRefused $refused) {
            $why = self::firstReason($refused);
            if ($htmx) {
                return $this->fragment('list', [], $this->model($request, $place, $page, $open, false, listError: $why), $refusedStatus);
            }

            return $this->stock->refused($request, $why, $place->routeName('edit'), $back, 'sections');
        }
        if ($action === 'section_remove' && $open === $section->reference) {
            $open = '';
            $back = $place->parameters;
        }
        if ($htmx) {
            $page = $this->pages->require($page->reference);
            $partials = ['checks', 'publish', 'add'];
            if ($open !== '' || $action === 'section_remove') {
                $partials[] = 'pane';
            }

            return $this->fragment('list', $partials, $this->model($request, $place->withPage($page), $page, $open, false));
        }

        return $this->stock->done($request, $message, $place->routeName('edit'), $back, 'sections');
    }

    private function move(Request $request, LandingPage $page, LandingSection $section, Actor $actor): string
    {
        $index = filter_var($request->request->get('index'), FILTER_VALIDATE_INT);
        if (!is_int($index)) {
            throw MarketingRefused::because('That is not a place on the page.');
        }
        $order = array_values(array_filter(explode(',', MarketingPages::text($request->request->get('order', ''))), static fn (string $one): bool => $one !== ''));
        $this->sections->arrange($page->reference, $section->reference, $index, $order === [] ? null : $order, $actor);

        return $section->label() . ' is moved.';
    }

    private function removeSection(LandingPage $page, LandingSection $section, Actor $actor): string
    {
        $this->sections->remove($page->reference, $section->reference, $actor);

        return 'The ' . mb_strtolower($section->label()) . ' section is removed, with what was written in it.';
    }

    /**
     * A change of the whole page from one of its forms: done, it goes back to the editor saying so; refused, with why.
     *
     * @param callable(LandingPage): string $change
     */
    private function act(Request $request, EditorPlace $place, string $need, callable $change, string $fragment = '', bool $fieldErrors = false): Response
    {
        $this->need($place, $need);
        $page = $this->page($place);
        if (!$this->stock->tokenIsValid($request)) {
            return $this->stock->expired($request, $place->routeName('edit'), $place->parameters, $fragment);
        }
        try {
            $done = $change($page);
        } catch (MarketingRefused $refused) {
            return $this->stock->refused($request, $fieldErrors ? CampaignsController::why($refused, 'Nothing was saved') : self::firstReason($refused), $place->routeName('edit'), $place->parameters, $fragment,
                errors: $fieldErrors ? $refused : []);
        }

        return $this->stock->done($request, $done, $place->routeName('edit'), $place->parameters, $fragment);
    }

    private function refusedPost(Request $request, EditorPlace $place, string $why): Response
    {
        $this->need($place, 'edit');

        return $this->stock->refused($request, $why, $place->routeName('edit'), $place->parameters);
    }

    /**
     * Pictures as a form posts them, made what a section stores: {source, ref} for each field posted as its source and the
     * references chosen for each source (refs), and a picture uploaded for a field (upload[field], with its title in
     * upload_title[field]) kept by the source chosen when it takes uploads (else the first that does) and chosen.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     * @throws MarketingRefused naming the field
     */
    private function pictureInput(array $input, Request $request, EditorPlace $place, LandingContext $context): array
    {
        foreach ($input as $field => $value) {
            if (is_array($value) && array_key_exists('source', $value) && (array_key_exists('refs', $value) || array_key_exists('ref', $value))) {
                $source = MarketingPages::text($value['source']);
                $refs = is_array($value['refs'] ?? null) ? $value['refs'] : [];
                $input[$field] = ['source' => $source, 'ref' => MarketingPages::text($refs[$source] ?? ($value['ref'] ?? ''))];
            }
        }
        $uploads = $request->files->get('upload');
        $titles = $request->request->all()['upload_title'] ?? [];
        foreach (is_array($uploads) ? $uploads : [] as $field => $file) {
            if (!$file instanceof UploadedFile || !is_string($field) || !array_key_exists($field, $input)) {
                continue;
            }
            if (!$place->access->upload) {
                throw new MarketingRefused([$field => 'You may choose pictures here, not upload them. Choose one of the pictures listed.']);
            }
            if (!$file->isValid()) {
                throw new MarketingRefused([$field => 'The picture could not be uploaded: ' . $file->getErrorMessage() . ' Try again with a smaller picture.']);
            }
            $chosen = is_array($input[$field]) ? MarketingPages::text($input[$field]['source'] ?? '') : '';
            $source = $this->uploadSource($context, $chosen) ?? throw new MarketingRefused([$field => 'This page has nowhere to keep an uploaded picture.']);
            try {
                $ref = $source->upload($file->getPathname(), $file->getClientOriginalName(), MarketingPages::text(is_array($titles) ? ($titles[$field] ?? '') : ''), $context, $this->stock->actor());
            } catch (MarketingRefused $refused) {
                throw new MarketingRefused([$field => self::firstReason($refused)]);
            }
            $input[$field] = ['source' => $source->key(), 'ref' => $ref];
        }

        return $input;
    }

    private function uploadSource(LandingContext $context, string $chosen): ?PictureSource
    {
        $sources = $this->pictureSources->applying($context);
        if (isset($sources[$chosen]) && $sources[$chosen]->mayUpload()) {
            return $sources[$chosen];
        }
        foreach ($sources as $source) {
            if ($source->mayUpload()) {
                return $source;
            }
        }

        return null;
    }

    /**
     * An answer for htmx: one part of the editor, and the others it changed as htmx partials, each swapped into its own
     * element on the page.
     *
     * @param list<string> $partials
     * @param array<string,mixed> $model
     */
    private function fragment(string $main, array $partials, array $model, int $status = Response::HTTP_OK): Response
    {
        return new Response($this->twig->render('@CattoPHP/marketing/landing-edit/answer.html.twig', $model + ['answer_main' => $main, 'answer_partials' => $partials]), $status);
    }

    /**
     * Everything the editor draws.
     *
     * @param array<string,mixed> $typed the open section's form as it was refused
     * @param array<string,string> $errors why each of its fields was refused
     * @param array{0:string,1:string}|null $paneNotice what the open section's last form did, as a tone and words
     * @param array<string,mixed> $settingsTyped
     * @param array<string,string> $settingsErrors
     * @param array{0:string,1:string}|null $settingsNotice
     * @return array<string,mixed>
     */
    private function model(Request $request, EditorPlace $place, LandingPage $page, string $open, bool $fromSession, array $typed = [], array $errors = [], ?array $paneNotice = null,
        string $listError = '', array $settingsTyped = [], array $settingsErrors = [], ?array $settingsNotice = null): array
    {
        $context = $this->pages->context($page);
        $sections = $this->pages->sections($page, $context);
        $access = $place->access;
        $parameters = $place->parameters;
        $url = fn (string $action, array $more = []): string => $this->stock->path($place->routeName($action), $parameters + $more);
        $templateTyped = [];
        $templateErrors = [];
        if ($fromSession) {
            // A plain form refused: what it held waits in the session for the form that sent it.
            $sessionErrors = $this->stock->errors($request);
            if ($open !== '') {
                $typed = TypedForm::take($request, $url('section_save', ['section' => $open]));
                $errors = $typed === [] ? [] : $sessionErrors;
            }
            $settingsTyped = TypedForm::take($request, $url('settings'));
            $settingsErrors = $settingsTyped === [] ? [] : $sessionErrors;
            $templateTyped = TypedForm::take($request, $url('template_save'));
            $templateErrors = $templateTyped === [] ? [] : $sessionErrors;
        }
        $others = array_values(array_filter($sections, static fn (LandingSection $section): bool => !$section->isHero()));
        $hero = array_values(array_filter($sections, static fn (LandingSection $section): bool => $section->isHero()))[0] ?? null;
        $order = array_map(static fn (LandingSection $section): string => $section->reference, $others);
        $rows = [];
        foreach ($others as $index => $section) {
            $rows[] = $this->row($section, $index, $context, $open, $url);
        }
        $published = $page->isPublished();
        $problems = $this->pages->problems($page, $context);
        $title = $this->pages->title($page);
        $about = $this->pages->about($page);
        $address = $this->pages->address($page);
        $versions = $this->versions->list($page);
        $templates = $this->templates->available($context, $access->savedTemplates);

        return [
            'title' => $title,
            'lead' => $page->isCampaignPage()
                ? 'The page the campaign\'s tracking links open. Its offers\' prices, pictures and buttons are read when the page is shown.'
                : 'A page that sells this ' . $context->offerWord . ', separate from its own page on the site. Its price and pictures come from the ' . $context->offerWord . ' each time the page is shown.',
            'kicker' => $place->kicker,
            'empty' => false,
            'back' => ['href' => $place->backHref, 'label' => $place->backLabel],
            'may_edit' => $access->edit,
            'may_publish' => $access->publish,
            'may_upload' => $access->upload,
            'may_save_templates' => $access->savedTemplates && $access->edit,
            'landing' => [
                'reference' => $page->reference,
                'kind' => $page->isCampaignPage() ? 'campaign' : 'offer',
                'published' => $published,
                'status' => $published ? 'Published' : 'Draft',
                'tone' => $published ? 'success' : 'neutral',
                'was_public' => $page->hasBeenPublic(),
                'address' => $address,
                'for' => $about,
                'for_href' => $this->forHref($page, $context),
                'problems' => $problems,
                'deletable' => !$page->hasBeenPublic(),
                'running_words' => $page->isCampaignPage() ? self::runningWords($context) : '',
            ],
            'urls' => [
                'edit' => $url('edit'),
                'preview' => $url('preview'),
                'publish' => $url('publish'),
                'unpublish' => $url('unpublish'),
                'delete' => $url('delete'),
                'settings' => $url('settings'),
                'add' => $url('section_add'),
                'template_save' => $url('template_save'),
                'start_again' => $url('start_again'),
            ],
            'hero' => $hero === null ? null : $this->row($hero, 0, $context, $open, $url),
            'rows' => $rows,
            'order' => $order,
            'open' => $open,
            'list_error' => $listError,
            'sketch' => $this->sketch($sections),
            'hero_shown' => $hero !== null && $hero->enabled && $hero->isValid(),
            'add_cards' => $this->addCards($context),
            'pane' => $open === '' ? null : $this->pane($page, $context, $sections, $open, $typed, $errors, $place, $url),
            'pane_notice' => $paneNotice,
            'settings' => $this->settingsModel($page, $context, $settingsTyped, $settingsErrors, $place),
            'settings_notice' => $settingsNotice,
            'versions' => array_map(fn (LandingVersion $version): array => $this->versionRow($version, $access, $url), $versions),
            'template_cards' => $this->templateCards($templates),
            'template_chosen' => $templates[0]->key ?? '',
            'template_typed' => ['name' => MarketingPages::text($templateTyped['name'] ?? ''), 'note' => MarketingPages::text($templateTyped['note'] ?? '')],
            'template_errors' => $templateErrors + ['name' => '', 'note' => ''],
            'shown_when_cards' => $page->isCampaignPage() ? $this->shownWhenCards() : [],
        ];
    }

    /**
     * @param callable(string, array<string,string>=): string $url
     * @return array<string,mixed> a section as a row of the list
     */
    private function row(LandingSection $section, int $index, LandingContext $context, string $open, callable $url): array
    {
        $kind = $section->kind;
        $one = ['section' => $section->reference];
        $missing = $section->isValid() && $kind !== null ? $kind->missing($section->data, $context) : [];

        return [
            'reference' => $section->reference,
            'label' => $section->label(),
            'summary' => match (true) {
                $kind === null => 'Remove it: the site no longer has this kind of section.',
                $section->invalid !== '' => 'Its saved copy no longer passes: ' . $section->invalid,
                default => $kind->summary($section->data, $context),
            },
            'known' => $kind !== null,
            'invalid' => $section->invalid,
            'enabled' => $section->enabled,
            'unsaved' => $section->unsaved,
            'needs_content' => $missing !== [],
            'is_hero' => $section->isHero(),
            'always_full' => $section->isHero() || ($kind?->alwaysFull() ?? false),
            'width' => $section->width,
            'shown_when' => $section->shownWhen,
            'shown_when_words' => $context->isCampaignPage() && $section->shownWhen !== 'always' ? LandingSection::SHOWN_WHEN[$section->shownWhen] ?? '' : '',
            'position' => $index,
            'open' => $section->reference === $open,
            'edit_href' => $url('edit', $one),
            'show_action' => $url('section_show', $one),
            'hide_action' => $url('section_hide', $one),
            'width_action' => $url('section_width', $one),
            'move_action' => $url('section_move', $one),
            'remove_action' => $url('section_remove', $one),
            'remove_confirm' => 'Remove the ' . mb_strtolower($section->label()) . ' section and what is written in it?',
        ];
    }

    /**
     * The small drawing of the page: the shown sections after the hero, in their rows of halves.
     *
     * @param list<LandingSection> $sections
     * @return list<list<array{key:string,label:string,width:string,kind:string}>>
     */
    private function sketch(array $sections): array
    {
        $blocks = [];
        foreach ($sections as $section) {
            if ($section->isHero() || !$section->enabled || !$section->isValid()) {
                continue;
            }
            $blocks[] = ['key' => $section->reference, 'label' => $section->label(), 'width' => ($section->kind?->alwaysFull() ?? false) ? 'full' : $section->width, 'kind' => $section->kindKey];
        }

        return HalfRows::rows($blocks);
    }

    /** @return list<array<string,mixed>> every kind that can be on the page, as a feature card */
    private function addCards(LandingContext $context): array
    {
        $cards = [];
        foreach ($this->kinds->applying($context) as $kind) {
            $on = $context->holds($kind->key());
            $taken = $kind->once() && $on;
            $cards[] = ['value' => $kind->key(), 'label' => $kind->label() . ($taken ? ' (already on the page)' : ($on ? ' (on the page, you can add another)' : '')),
                'icon' => $this->marketing->icon($kind->icon()), 'note' => $kind->description(), 'disabled' => $taken];
        }

        return $cards;
    }

    /**
     * The open section's form: its kind's fields as typed or saved, why each was refused, what its form needs, its
     * pictures' choices, and its preview.
     *
     * @param list<LandingSection> $sections
     * @param array<string,mixed> $typed
     * @param array<string,string> $errors
     * @param callable(string, array<string,string>=): string $url
     * @return array<string,mixed>|null
     */
    private function pane(LandingPage $page, LandingContext $context, array $sections, string $open, array $typed, array $errors, EditorPlace $place, callable $url): ?array
    {
        $section = array_values(array_filter($sections, static fn (LandingSection $one): bool => $one->reference === $open))[0] ?? null;
        if ($section === null) {
            return null;
        }
        $kind = $section->kind;
        $values = $typed !== [] ? $typed : $section->data;
        $base = ['reference' => $section->reference, 'label' => $section->label(), 'known' => $kind !== null, 'enabled' => $section->enabled, 'unsaved' => $section->unsaved,
            'invalid' => $section->invalid, 'shown_when' => $section->shownWhen, 'shown_when_action' => $url('section_shown_when', ['section' => $section->reference]),
            'remove_action' => $url('section_remove', ['section' => $section->reference]), 'is_hero' => $section->isHero(),
            'remove_confirm' => 'Remove the ' . mb_strtolower($section->label()) . ' section and what is written in it?'];
        if ($kind === null) {
            return $base + ['description' => 'This site no longer has this kind of section, so it is left off the page. Remove it.'];
        }
        $card = $section->isHero() ? $this->offerCard($page, $context) : null;

        return $base + [
            'kind' => $kind->key(),
            'description' => $kind->description(),
            'default_heading' => $kind->defaultHeading(),
            'form_template' => $kind->formTemplate(),
            'form' => $kind->form($context),
            'values' => $values,
            'errors' => $errors,
            'pictures' => $this->pictureFields($kind, $values, $context, $place),
            'action' => $url('section_save', ['section' => $section->reference]),
            'cancel_href' => $url('edit') . '#sections',
            'targets' => $this->targets($sections, $section) + ($card !== null ? $this->offerLinks($card, $context) : []),
            // What the hero shows under its headline when its words are left empty, as the field's placeholder.
            'text_placeholder' => $card?->heroWords() ?? '',
            'campaign_page' => $context->isCampaignPage(),
            'offer_word' => $context->offerWord,
            'offers_heading' => $context->offersHeading,
            'preview' => $this->sectionPreview($page, $section),
        ];
    }

    /**
     * Each picture field of a kind (one whose default is none or a {source, ref}) as its form draws it: the sources as
     * feature cards (and No picture), each source's pictures to choose from, the one chosen, and whether a picture may be
     * uploaded here.
     *
     * @param array<string,mixed> $values
     * @return array<string,array<string,mixed>>
     */
    private function pictureFields(SectionKind $kind, array $values, LandingContext $context, EditorPlace $place): array
    {
        $fields = [];
        foreach ($kind->defaults() as $field => $default) {
            if ($default === null || (is_array($default) && array_key_exists('source', $default))) {
                $fields[(string) $field] = $this->pictureField((string) $field, $values[$field] ?? null, $context, $place);
            }
        }

        return $fields;
    }

    /** @return array<string,mixed> */
    private function pictureField(string $field, mixed $value, LandingContext $context, EditorPlace $place): array
    {
        $chosen = is_array($value) ? MarketingPages::text($value['source'] ?? '') : '';
        $chosen = $chosen === '' ? 'none' : $chosen;
        $refs = is_array($value) && is_array($value['refs'] ?? null) ? $value['refs'] : [];
        $cards = [];
        $choices = [];
        $upload = '';
        foreach ($this->pictureSources->applying($context) as $key => $source) {
            $options = $source->choices($context);
            $mayUpload = $source->mayUpload() && $place->access->upload;
            $cards[] = ['value' => $key, 'label' => $source->label(), 'icon' => $this->marketing->icon(self::SOURCE_ICONS[$key] ?? 'images'),
                'note' => $key === 'offer' ? 'As its own page shows it' : ($options === [] ? ($mayUpload ? 'Upload one below' : 'None to choose from yet') : MarketingPages::count(count($options), 'picture to choose from', 'pictures to choose from'))];
            if ($options !== [] || $key === 'offer') {
                $ref = MarketingPages::text($refs[$key] ?? ($chosen === $key && is_array($value) ? ($value['ref'] ?? '') : ''));
                $choices[] = ['source' => $key, 'label' => $source->label(), 'options' => $options, 'ref' => $ref, 'own' => $key === 'offer'];
            }
            if ($mayUpload && $upload === '') {
                $upload = $key;
            }
        }
        $cards[] = ['value' => 'none', 'label' => 'No picture', 'icon' => $this->marketing->icon(self::SOURCE_ICONS['none']), 'note' => 'The words alone'];

        return ['field' => $field, 'id' => 'landing-' . str_replace('_', '-', $field), 'source' => $chosen, 'cards' => $cards, 'choices' => $choices, 'upload' => $upload];
    }

    /**
     * The parts of the page a button may lead to: the kinds on the page other than the section itself, by key, with the
     * words a button says for each.
     *
     * @param list<LandingSection> $sections
     * @return array<string,string>
     */
    private function targets(array $sections, LandingSection $self): array
    {
        $targets = [];
        foreach ($sections as $section) {
            if ($section->reference === $self->reference || $section->isHero() || $section->kind === null || isset($targets[$section->kindKey])) {
                continue;
            }
            $targets[$section->kindKey] = $section->kind instanceof JumpTarget ? $section->kind->jumpLabel() . ' (' . $section->label() . ')' : $section->label();
        }

        return $targets;
    }

    /**
     * The addresses the page's offer's card gives a second button (such as a free preview), by their key, in words.
     *
     * @return array<string,string>
     */
    private function offerLinks(OfferCard $card, LandingContext $context): array
    {
        $links = [];
        foreach ($card->links as $key => $link) {
            $links[(string) $key] = $link['label'] . ' (' . ($context->offer()->label ?? $card->label) . ')';
        }

        return $links;
    }

    /** The page's offer's card as the project draws it for a preview, or null on a campaign's page. */
    private function offerCard(LandingPage $page, LandingContext $context): ?OfferCard
    {
        $offer = $context->offer();
        if ($offer === null || $this->landing === null) {
            return null;
        }

        return $this->landing->card($offer, LandingVisit::preview($page, $this->pages->address($page), $this->account()), new CardOptions());
    }

    /**
     * @param array<string,mixed> $typed
     * @param array<string,string> $errors
     * @return array<string,mixed>
     */
    private function settingsModel(LandingPage $page, LandingContext $context, array $typed, array $errors, EditorPlace $place): array
    {
        $value = static fn (string $key, ?string $saved): string => array_key_exists($key, $typed) ? MarketingPages::text($typed[$key]) : (string) $saved;
        $share = array_key_exists('share_picture', $typed) ? $typed['share_picture'] : $page->sharePicture;
        $notRunning = [];
        foreach (NotRunning::CHOICES as $key => [$label, $note]) {
            $notRunning[] = ['value' => $key, 'label' => $label, 'note' => $note, 'icon' => $this->marketing->icon(match ($key) { NotRunning::KEEP => 'file-check', NotRunning::NOTE => 'message-square-warning', default => 'signpost' })];
        }

        return [
            'offer_page' => $page->isOfferPage(),
            'prefix' => '/' . $this->pages->landingPrefix() . '/',
            'slug' => $value('slug', $page->slug),
            'campaign_address' => $page->isCampaignPage() ? $this->pages->address($page) : '',
            'meta_title' => $value('meta_title', $page->metaTitle),
            'meta_description' => $value('meta_description', $page->metaDescription),
            'title_placeholder' => $this->pages->title($page),
            'share' => $this->pictureField('share_picture', $share, $context, $place),
            'not_running' => $value('not_running', $page->notRunning),
            'not_running_to' => $value('not_running_to', $page->notRunningTo),
            'not_running_cards' => $notRunning,
            'destination' => $context->campaign->destination ?? '/',
            'errors' => $errors + ['slug' => '', 'meta_title' => '', 'meta_description' => '', 'share_picture' => '', 'not_running' => '', 'not_running_to' => ''],
        ];
    }

    /**
     * @param callable(string, array<string,string>=): string $url
     * @return array<string,mixed>
     */
    private function versionRow(LandingVersion $version, LandingAccess $access, callable $url): array
    {
        $one = ['version' => 'v' . $version->number];

        return [
            'number' => $version->number,
            'at' => $this->marketing->moment($version->at),
            'what' => $version->whatChanged,
            'state' => $version->state(),
            'current' => $version->isCurrent(),
            'by' => $version->madeBy === null ? '' : $this->names->name($version->madeBy),
            'view_href' => $version->isPublished() ? $url('version', $one) : '',
            'use_action' => $version->isPublished() && !$version->isCurrent() && $access->edit ? $url('version_use', $one) : '',
        ];
    }

    /**
     * @param list<LandingTemplate> $templates
     * @return list<array<string,mixed>>
     */
    private function templateCards(array $templates): array
    {
        return array_map(fn (LandingTemplate $template): array => ['value' => $template->key, 'label' => $template->name,
            'icon' => $this->marketing->icon($template->builtIn ? 'layout-template' : 'bookmark'), 'note' => $template->note !== '' ? $template->note : 'Saved by someone here'], $templates);
    }

    /** @return list<array<string,mixed>> */
    private function shownWhenCards(): array
    {
        $cards = [];
        foreach (LandingSection::SHOWN_WHEN as $key => $label) {
            $cards[] = ['value' => $key, 'label' => $label, 'icon' => $this->marketing->icon(match ($key) { 'always' => 'eye', 'running' => 'circle-play', default => 'circle-pause' }), 'note' => self::SHOWN_WHEN_NOTES[$key]];
        }

        return $cards;
    }

    /** The open section from the address (?section=), when it is one of the page's. */
    private function openSection(Request $request, EditorPlace $place, LandingPage $page): string
    {
        $open = strtolower(trim(MarketingPages::text($request->query->get('section', ''))));

        return $open !== '' && $this->sections->get($page, $open) !== null ? $open : '';
    }

    private function forHref(LandingPage $page, LandingContext $context): string
    {
        if ($page->isOfferPage()) {
            return (string) ($context->offer()->href ?? '');
        }
        try {
            return $this->stock->path('cattophp_marketing_campaign', ['code' => $context->campaign->code ?? '']);
        } catch (RoutingException) {
            return '';
        }
    }

    private function page(EditorPlace $place): LandingPage
    {
        // Read afresh, so an answer after a change shows the page as it is now.
        return $this->pages->get((string) $place->page?->reference) ?? throw new NotFoundHttpException('There is no such landing page.');
    }

    private function need(EditorPlace $place, string $what): void
    {
        $allowed = match ($what) {
            'edit' => $place->access->edit,
            'publish' => $place->access->publish,
            'savedTemplates' => $place->access->savedTemplates && $place->access->edit,
            default => $place->access->see,
        };
        if (!$allowed) {
            throw new AccessDeniedException('This landing page is not one you may change in this way.');
        }
    }

    private function offerWord(EditorPlace $place): string
    {
        return $place->offerType === null ? 'campaign' : mb_strtolower($this->project->offerKinds()[$place->offerType] ?? 'offer');
    }

    private function views(): LandingViewBuilder
    {
        return $this->views ?? throw new NotFoundHttpException('Previews are not drawn here.');
    }

    /** A preview or a kept version, drawn as the public page is, for its editors alone: never indexed, never kept by a cache. */
    private function drawn(LandingView $view): Response
    {
        return new Response($this->views()->draw($view), Response::HTTP_OK, ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    /** The open section drawn as the page draws it, its buttons doing nothing; '' when it has nothing to show. */
    private function sectionPreview(LandingPage $page, LandingSection $section): string
    {
        if ($this->views === null) {
            return '';
        }
        $view = $this->views->sectionPreview($page, $section->reference, $this->account());

        return $view === null ? '' : $this->views->drawSection($view);
    }

    /** The reader as the activity log names them, for a preview's buttons; none for the system. */
    private function account(): ?string
    {
        return $this->stock->actor()->id;
    }

    private static function versionNumber(string $version): int
    {
        return preg_match('/^v([1-9][0-9]{0,5})$/', $version, $found) === 1 ? (int) $found[1] : throw new NotFoundHttpException('There is no such version of this page.');
    }

    private static function firstReason(MarketingRefused $refused): string
    {
        $errors = array_values(array_filter($refused->errors(), static fn (string $why): bool => $why !== ''));

        return $errors[0] ?? $refused->getMessage();
    }

    /** @param list<string> $skipped */
    private static function skipped(array $skipped): string
    {
        return $skipped === [] ? '' : ' Left out, since they do not belong on this page: ' . implode(', ', $skipped) . '.';
    }

    private static function runningWords(LandingContext $context): string
    {
        return match (NotRunning::state($context->campaign, $context->now)) {
            'running' => 'The campaign is running',
            'not_started' => 'The campaign has not started',
            'paused' => 'The campaign is paused',
            'draft' => 'The campaign is a draft, so its page is seen only here',
            default => 'The campaign has ended',
        };
    }
}
