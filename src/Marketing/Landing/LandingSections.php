<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

use CattoPHP\Activity\Actor;
use CattoPHP\Marketing\MarketingRefused;
use CattoPHP\Marketing\Pictures;
use CattoPHP\Marketing\Rows;
use CattoPHP\Ui\TreeArrangement;
use CattoPHP\Ui\TreeMoveRefused;
use Doctrine\DBAL\Connection;

/**
 * The sections of a landing page, changed: added (at the end, a hero first; hidden until its first save), saved (checked
 * against its kind), shown, hidden, made half or full width, shown always or only while the campaign runs or does not,
 * removed and moved (by drag, keyboard or menu: an index among the others, or top, up, down, bottom; a move made from an
 * order that is out of date is refused). A page holds a kind marked once at most once. Each change takes the page's row
 * lock in one transaction, writes positions 1 to n with their constraint deferred, and, on a published page, keeps a
 * version straight after it. The whole list is also replaced from a template or an earlier version. Every change is in
 * the activity log with identifiers and field names, never copy, and announced as LandingPageChanged.
 */
final class LandingSections
{
    public const DIRECTIONS = ['top', 'up', 'down', 'bottom'];

    private const STALE = 'The sections were changed elsewhere since this page was loaded. The saved order is shown.';

    /** @var list<string> pictures a save stopped naming, removed once the save is done */
    private array $unnamed = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly LandingPages $pages,
        private readonly SectionKinds $kinds,
        private readonly LandingVersions $versions,
        private readonly LandingTemplates $templates,
        private readonly ?PictureSources $pictureSources = null,
        private readonly ?Pictures $pictures = null,
    ) {
    }

    /** @return list<LandingSection> a page's sections in order, each checked against its kind */
    public function list(LandingPage $page): array
    {
        return $this->pages->sections($page);
    }

    /** A section of a page by its reference; another page's is simply not found. */
    public function get(LandingPage $page, string $reference): ?LandingSection
    {
        foreach ($this->pages->sections($page) as $section) {
            if ($section->reference === strtolower(trim($reference))) {
                return $section;
            }
        }

        return null;
    }

    /**
     * Adds a section of a kind at the end of the page (a hero goes first), with its kind's defaults and hidden until it is
     * first saved.
     *
     * @throws MarketingRefused
     */
    public function add(string $pageReference, string $kindKey, ?Actor $actor = null): LandingSection
    {
        $kind = $this->kinds->get($kindKey) ?? throw new MarketingRefused(['kind' => 'Choose a kind of section.']);
        [$page, $reference] = $this->connection->transactional(function () use ($pageReference, $kind, $actor): array {
            $page = $this->pages->lock($pageReference);
            $context = $this->pages->context($page);
            if (!$kind->appliesTo($context)) {
                throw new MarketingRefused(['kind' => sprintf('%s does not belong on this page.', $kind->label())]);
            }
            $sections = $this->pages->sections($page, $context);
            if ($kind->once() && array_filter($sections, static fn(LandingSection $section): bool => $section->kindKey === $kind->key()) !== []) {
                throw new MarketingRefused(['kind' => sprintf('The page already has a %s section; a page has only one.', mb_strtolower($kind->label()))]);
            }
            $reference = $this->pages->freeReference();
            $others = count(array_filter($sections, static fn(LandingSection $section): bool => !$section->isHero()));
            $this->pages->insertSections($page->id, [['kind' => $kind->key(), 'width' => 'full', 'shown_when' => 'always', 'enabled' => false, 'unsaved' => true,
                'data' => $kind->defaults(), 'reference' => $reference]], $others + 1);
            $this->pages->keepVersion($page, sprintf('A %s section was added', mb_strtolower($kind->label())), $actor);

            return [$page, $reference];
        });
        $section = $this->get($page, $reference) ?? throw MarketingRefused::because('The section could not be read back.');
        $this->changed('marketing.landing_page.section_added', $page, $section, 'Landing page section added: ' . $kind->label() . '.', [], $actor);

        return $section;
    }

    /**
     * Saves a section's copy and settings as typed, checked against its kind; its pictures must still be there. A section
     * never saved is shown at its first save.
     *
     * @param array<string,mixed> $input
     * @throws MarketingRefused by field (the kind's CopyRefused), or for the section as a whole
     */
    public function save(string $pageReference, string $sectionReference, array $input, ?Actor $actor = null): LandingSection
    {
        [$page, $section, $changed] = $this->connection->transactional(function () use ($pageReference, $sectionReference, $input, $actor): array {
            [$page, $section, $context] = $this->locked($pageReference, $sectionReference);
            $kind = $section->kind ?? throw MarketingRefused::because('This kind of section is no longer on the site: remove it.');
            try {
                $data = $kind->normalise($input, $context);
            } catch (CopyRefused $refused) {
                throw new MarketingRefused([$refused->field => $refused->getMessage()]);
            } catch (\InvalidArgumentException $refused) {
                throw MarketingRefused::because($refused->getMessage());
            }
            foreach ($kind->pictures($data) as $picture) {
                if (($picture['ref'] !== '' || $picture['source'] === OfferPicture::KEY) && $this->pictureSources !== null && !$this->pictureSources->exists($picture, $context)) {
                    throw new MarketingRefused(['picture' => 'That picture is no longer there: choose another.']);
                }
            }
            $before = $section->invalid === '' ? $section->data : [];
            $changed = array_keys(array_filter($data, static fn(mixed $value, string $key): bool => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
            if ($changed === [] && !$section->unsaved) {
                return [$page, $section, []];
            }
            $this->connection->executeStatement('UPDATE ' . LandingPages::SECTIONS . ' SET data = CAST(:data AS jsonb), enabled = CASE WHEN unsaved THEN true ELSE enabled END, unsaved = false,
                updated_at = :now WHERE id = :id', ['data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'now' => Rows::sql($context->now), 'id' => $section->id]);
            $this->pages->keepVersion($page, sprintf('The %s section changed', $kind->label()), $actor);
            $this->removeUnusedLater($section->data, $data);

            return [$page, $section, $changed === [] ? ['shown'] : $changed];
        });
        $saved = $this->get($page, $section->reference) ?? $section;
        if ($changed !== []) {
            $this->changed('marketing.landing_page.section_saved', $page, $saved, 'Landing page section edited: ' . $saved->label() . '.', ['changed' => $changed], $actor);
        }
        $this->tidyPictures();

        return $saved;
    }

    /** Shows a hidden section. */
    public function show(string $pageReference, string $sectionReference, ?Actor $actor = null): LandingSection
    {
        return $this->setEnabled($pageReference, $sectionReference, true, $actor);
    }

    /** Hides a section: kept, left off the page, never checked before publishing. */
    public function hide(string $pageReference, string $sectionReference, ?Actor $actor = null): LandingSection
    {
        return $this->setEnabled($pageReference, $sectionReference, false, $actor);
    }

    /**
     * A section's width: half or full. Two halves one after the other share a row; the hero and the countdown are always
     * full.
     *
     * @throws MarketingRefused
     */
    public function width(string $pageReference, string $sectionReference, string $width, ?Actor $actor = null): LandingSection
    {
        if (!in_array($width, LandingSection::WIDTHS, true)) {
            throw new MarketingRefused(['width' => 'Choose half or full width.']);
        }

        return $this->setColumn($pageReference, $sectionReference, 'width', $width, $actor, static function (LandingSection $section) use ($width): void {
            if (($section->isHero() || ($section->kind?->alwaysFull() ?? false)) && $width !== 'full') {
                throw new MarketingRefused(['width' => sprintf('%s is always the full width of the page.', $section->isHero() ? 'The hero' : $section->label())]);
            }
        }, $width === 'half' ? 'made half width' : 'made full width', 'marketing.landing_page.section_width');
    }

    /**
     * When a section of a campaign's page is shown: always, while the campaign runs, or while it is not running. An
     * offer's page is always running, so its sections are always shown.
     *
     * @throws MarketingRefused
     */
    public function shownWhen(string $pageReference, string $sectionReference, string $shownWhen, ?Actor $actor = null): LandingSection
    {
        if (!array_key_exists($shownWhen, LandingSection::SHOWN_WHEN)) {
            throw new MarketingRefused(['shown_when' => 'Choose when the section is shown.']);
        }
        $page = $this->pages->require($pageReference);
        if ($page->isOfferPage() && $shownWhen !== 'always') {
            throw new MarketingRefused(['shown_when' => 'An offer\'s page is always running, so its sections are always shown.']);
        }

        return $this->setColumn($pageReference, $sectionReference, 'shown_when', $shownWhen, $actor, null, 'shown ' . mb_strtolower(LandingSection::SHOWN_WHEN[$shownWhen]),
            'marketing.landing_page.section_shown_when');
    }

    /** Removes a section and closes the gap; a picture only it named goes too. */
    public function remove(string $pageReference, string $sectionReference, ?Actor $actor = null): void
    {
        [$page, $section] = $this->connection->transactional(function () use ($pageReference, $sectionReference, $actor): array {
            [$page, $section] = $this->locked($pageReference, $sectionReference);
            $this->connection->delete(LandingPages::SECTIONS, ['id' => $section->id]);
            $this->writeOrder($page, array_map(static fn(LandingSection $s): int => $s->id, array_values(array_filter($this->pages->sections($page), static fn(LandingSection $s): bool => !$s->isHero()))));
            $this->pages->keepVersion($page, sprintf('The %s section was removed', $section->label()), $actor);

            return [$page, $section];
        });
        foreach (LandingPages::uploadedPictures($section->data) as $name) {
            $this->pictures?->removeUnused($name);
        }
        $this->changed('marketing.landing_page.section_removed', $page, $section, 'Landing page section removed: ' . $section->label() . '.', [], $actor);
    }

    /**
     * Drag and drop: a section goes to an index among the sections after the hero (0 is the first). When the order the
     * page showed is given (the sections' references), a different stored order refuses the move rather than guessing.
     *
     * @param list<string>|null $shown
     * @throws MarketingRefused
     */
    public function arrange(string $pageReference, string $sectionReference, int $index, ?array $shown = null, ?Actor $actor = null): void
    {
        $this->reorder($pageReference, $sectionReference, $shown, $actor, static fn(TreeArrangement $tree, array $rows, int $id): array => $tree->place($rows, [$id], null, $index));
    }

    /**
     * The menu and the keyboard: top, up, down or bottom.
     *
     * @throws MarketingRefused
     */
    public function step(string $pageReference, string $sectionReference, string $direction, ?Actor $actor = null): void
    {
        if (!in_array($direction, self::DIRECTIONS, true)) {
            throw MarketingRefused::because('Choose a valid move.');
        }
        $this->reorder($pageReference, $sectionReference, null, $actor, static fn(TreeArrangement $tree, array $rows, int $id): array => $tree->move($rows, [$id], $direction));
    }

    /**
     * Starts a page again from a template: its sections replaced (on a published page the old ones stay in the versions),
     * the kinds that do not belong left out and named.
     *
     * @throws MarketingRefused
     */
    public function startAgain(string $pageReference, string $templateKey, bool $withSaved, ?Actor $actor = null): TemplateResult
    {
        $template = $this->templates->get($templateKey);
        $page = $this->pages->require($pageReference);
        $context = $this->pages->context($page);
        if ($template === null || !$template->suitsPage($context) || (!$template->builtIn && !$withSaved)) {
            throw new MarketingRefused(['template' => 'Choose one of the templates.']);
        }
        $applied = $this->templates->apply($template, $context);
        $old = $this->replace($page, $applied->sections, sprintf('Started again from the template %s', $template->name), null, $actor);
        foreach ($old as $name) {
            $this->pictures?->removeUnused($name);
        }
        $page = $this->pages->require($pageReference);
        $this->pages->changed('marketing.landing_page.template_applied', $page, null, 'Landing page started again from the template ' . $template->name . '.',
            ['template' => $template->key, 'skipped' => $applied->skipped], $actor);

        return new TemplateResult($page, $applied->skipped);
    }

    /**
     * Puts an earlier version's content back on the page: its sections (each with its reference again where it is free) and
     * its settings, its address kept as it is now. On a published page this is itself a new version ("Version 3 used
     * again").
     *
     * @throws MarketingRefused
     */
    public function useVersion(string $pageReference, int $number, ?Actor $actor = null): LandingPage
    {
        $page = $this->pages->require($pageReference);
        $version = $this->versions->get($page, $number);
        if ($version === null || !$version->isPublished()) {
            throw MarketingRefused::because('There is no such version to use again.');
        }
        $sections = [];
        foreach ($version->sections() as $section) {
            $kind = $this->kinds->get(Rows::text($section['kind'] ?? ''));
            if ($kind === null) {
                continue;
            }
            $sections[] = ['kind' => $kind->key(), 'width' => Rows::text($section['width'] ?? 'full') === 'half' && !$kind->alwaysFull() ? 'half' : 'full',
                'shown_when' => $page->isCampaignPage() && array_key_exists(Rows::text($section['shown_when'] ?? ''), LandingSection::SHOWN_WHEN) ? Rows::text($section['shown_when']) : 'always',
                'enabled' => Rows::bool($section['enabled'] ?? true), 'data' => is_array($section['data'] ?? null) ? $section['data'] : $kind->defaults(),
                'reference' => Rows::text($section['reference'] ?? '')];
        }
        $old = $this->replace($page, $sections, sprintf('Version %d used again', $number), $version->settings(), $actor);
        foreach ($old as $name) {
            $this->pictures?->removeUnused($name);
        }
        $page = $this->pages->require($pageReference);
        $this->pages->changed('marketing.landing_page.version_used', $page, null, sprintf('Landing page version %d used again.', $number), ['version' => $number], $actor);

        return $page;
    }

    /**
     * Replaces every section of a page (and, from a version, its settings), under its lock, keeping a version on a
     * published page. Gives the pictures the old sections named, to remove once nothing else does.
     *
     * @param list<array{kind:string,width:string,shown_when:string,enabled:bool,data:array<string,mixed>,reference?:string}> $sections
     * @param array<string,mixed>|null $settings
     * @return list<string>
     */
    private function replace(LandingPage $page, array $sections, string $whatChanged, ?array $settings, ?Actor $actor): array
    {
        return $this->connection->transactional(function () use ($page, $sections, $whatChanged, $settings, $actor): array {
            $page = $this->pages->lock($page->reference);
            $old = [];
            foreach ($this->pages->sections($page) as $section) {
                $old = [...$old, ...LandingPages::uploadedPictures($section->data)];
            }
            $this->connection->delete(LandingPages::SECTIONS, ['landing_page_id' => $page->id]);
            $this->pages->insertSections($page->id, $sections);
            if ($settings !== null) {
                $share = is_array($settings['share_picture'] ?? null) ? json_encode($settings['share_picture'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : null;
                $notRunning = array_key_exists(Rows::text($settings['not_running'] ?? ''), NotRunning::CHOICES) ? Rows::text($settings['not_running']) : $page->notRunning;
                $this->connection->executeStatement('UPDATE ' . LandingPages::TABLE . ' SET meta_title = :title, meta_description = :description, share_picture = CAST(:share AS jsonb),
                    not_running = :not_running, not_running_to = :to WHERE id = :id', ['title' => Rows::nullableText($settings['meta_title'] ?? null),
                    'description' => Rows::nullableText($settings['meta_description'] ?? null), 'share' => $share, 'not_running' => $notRunning,
                    'to' => Rows::nullableText($settings['not_running_to'] ?? null), 'id' => $page->id]);
            }
            $this->pages->keepVersion($page, $whatChanged, $actor);

            return array_values(array_unique($old));
        });
    }

    private function setEnabled(string $pageReference, string $sectionReference, bool $enabled, ?Actor $actor): LandingSection
    {
        [$page, $section, $done] = $this->connection->transactional(function () use ($pageReference, $sectionReference, $enabled, $actor): array {
            [$page, $section] = $this->locked($pageReference, $sectionReference);
            if ($section->enabled === $enabled && !($enabled && $section->unsaved)) {
                return [$page, $section, false];
            }
            $this->connection->executeStatement('UPDATE ' . LandingPages::SECTIONS . ' SET enabled = :enabled, unsaved = CASE WHEN :enabled THEN false ELSE unsaved END, updated_at = now() WHERE id = :id',
                ['enabled' => $enabled ? 'true' : 'false', 'id' => $section->id]);
            $this->pages->keepVersion($page, sprintf('The %s section was %s', $section->label(), $enabled ? 'shown' : 'hidden'), $actor);

            return [$page, $section, true];
        });
        $changed = $this->get($page, $section->reference) ?? $section;
        if ($done) {
            $this->changed($enabled ? 'marketing.landing_page.section_shown' : 'marketing.landing_page.section_hidden', $page, $changed,
                'Landing page section ' . ($enabled ? 'shown' : 'hidden') . ': ' . $changed->label() . '.', [], $actor);
        }

        return $changed;
    }

    /** @param (callable(LandingSection):void)|null $check */
    private function setColumn(string $pageReference, string $sectionReference, string $column, string $value, ?Actor $actor, ?callable $check, string $words, string $action): LandingSection
    {
        [$page, $section, $done] = $this->connection->transactional(function () use ($pageReference, $sectionReference, $column, $value, $actor, $check, $words): array {
            [$page, $section] = $this->locked($pageReference, $sectionReference);
            if ($check !== null) {
                $check($section);
            }
            $current = $column === 'width' ? $section->width : $section->shownWhen;
            if ($current === $value) {
                return [$page, $section, false];
            }
            $this->connection->executeStatement('UPDATE ' . LandingPages::SECTIONS . ' SET ' . ($column === 'width' ? 'width' : 'shown_when') . ' = :value, updated_at = now() WHERE id = :id',
                ['value' => $value, 'id' => $section->id]);
            $this->pages->keepVersion($page, sprintf('The %s section was %s', $section->label(), $words), $actor);

            return [$page, $section, true];
        });
        $changed = $this->get($page, $section->reference) ?? $section;
        if ($done) {
            $this->changed($action, $page, $changed, 'Landing page section ' . $words . ': ' . $changed->label() . '.', [$column => $value], $actor);
        }

        return $changed;
    }

    /**
     * @param list<string>|null $shown
     * @param callable(TreeArrangement, list<array{id:int,parent:null}>, int):list<array{id:int,parent:int|null}> $change
     */
    private function reorder(string $pageReference, string $sectionReference, ?array $shown, ?Actor $actor, callable $change): void
    {
        $moved = $this->connection->transactional(function () use ($pageReference, $sectionReference, $shown, $actor, $change): ?array {
            [$page, $section] = $this->locked($pageReference, $sectionReference);
            if ($section->isHero()) {
                throw MarketingRefused::because('The hero is always at the top of the page.');
            }
            $others = array_values(array_filter($this->pages->sections($page), static fn(LandingSection $s): bool => !$s->isHero()));
            $references = array_map(static fn(LandingSection $s): string => $s->reference, $others);
            if ($shown !== null && $shown !== $references) {
                throw MarketingRefused::because(self::STALE);
            }
            $ids = array_map(static fn(LandingSection $s): int => $s->id, $others);
            $tree = new TreeArrangement(1);
            try {
                $order = array_map(static fn(array $row): int => (int) $row['id'], $change($tree, array_map(static fn(int $id): array => ['id' => $id, 'parent' => null], $ids), $section->id));
                $tree->assertComplete($ids, array_map(static fn(int $id): array => ['id' => $id, 'parent' => null], $order));
            } catch (TreeMoveRefused $refused) {
                throw MarketingRefused::because(match ($refused->reason) {
                    TreeMoveRefused::BAD_POSITION => 'That is not a place on the page.',
                    TreeMoveRefused::STALE => self::STALE,
                    default => 'That move is not possible.',
                });
            }
            if ($order === $ids) {
                return null;
            }
            $this->writeOrder($page, $order);
            $from = (int) array_search($section->id, $ids, true) + 1;
            $to = (int) array_search($section->id, $order, true) + 1;
            $this->pages->keepVersion($page, sprintf('The %s section was moved', $section->label()), $actor);

            return [$page, $section, $from, $to];
        });
        if ($moved !== null) {
            [$page, $section, $from, $to] = $moved;
            $this->changed('marketing.landing_page.section_moved', $page, $section, sprintf('Landing page section moved: %s from %d to %d.', $section->label(), $from, $to),
                ['from_position' => $from, 'to_position' => $to], $actor);
        }
    }

    /**
     * Writes the order of the sections after the hero: each id its place, 1 to n, with the position constraint deferred
     * until the end; only rows whose place changed are written.
     *
     * @param list<int> $ids
     */
    private function writeOrder(LandingPage $page, array $ids): void
    {
        $this->connection->executeStatement('SET CONSTRAINTS cattophp_marketing_landing_sections_position_key DEFERRED');
        foreach ($ids as $index => $id) {
            $this->connection->executeStatement('UPDATE ' . LandingPages::SECTIONS . ' SET position = :position, updated_at = now() WHERE id = :id AND landing_page_id = :page AND position <> :position',
                ['position' => $index + 1, 'id' => $id, 'page' => $page->id]);
        }
        $this->connection->executeStatement('SET CONSTRAINTS cattophp_marketing_landing_sections_position_key IMMEDIATE');
    }

    /**
     * The page (locked), the section and the page's context, inside the transaction.
     *
     * @return array{0:LandingPage,1:LandingSection,2:LandingContext}
     */
    private function locked(string $pageReference, string $sectionReference): array
    {
        $page = $this->pages->lock($pageReference);
        $context = $this->pages->context($page);
        foreach ($this->pages->sections($page, $context) as $section) {
            if ($section->reference === strtolower(trim($sectionReference))) {
                return [$page, $section, $context];
            }
        }
        throw MarketingRefused::because('That section is not on this page.');
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private function removeUnusedLater(array $before, array $after): void
    {
        $this->unnamed = array_values(array_diff(LandingPages::uploadedPictures($before), LandingPages::uploadedPictures($after)));
    }

    private function tidyPictures(): void
    {
        foreach ($this->unnamed as $name) {
            $this->pictures?->removeUnused($name);
        }
        $this->unnamed = [];
    }

    /** @param array<string,mixed> $details */
    private function changed(string $action, LandingPage $page, LandingSection $section, string $summary, array $details, ?Actor $actor): void
    {
        $this->pages->changed($action, $this->pages->byId($page->id) ?? $page, $section->kindKey, $summary, ['section' => $section->reference] + $details, $actor);
    }
}
