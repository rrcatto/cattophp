<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use CattoPHP\Marketing\Campaigns;
use CattoPHP\Marketing\MarketingRefused;
use CattoPHP\Marketing\Pictures;
use CattoPHP\Marketing\Rows;
use Doctrine\DBAL\Connection;

/**
 * Landing page templates: the built-in ones (each TemplateProvider's) and those saved from a page (a marketing tool: only
 * someone who manages campaigns sees or saves them). A saved template keeps the page's sections with their copy, widths
 * and settings, without the offers chosen. Applying one to a page leaves out the kinds that do not belong there (and says
 * which), takes the page's offers into its offers sections, and checks each section's data against its kind.
 */
final class LandingTemplates
{
    public const TABLE = 'cattophp_marketing_landing_templates';
    public const SAVED = 'saved:';

    /** @param iterable<TemplateProvider> $providers */
    public function __construct(
        private readonly Connection $connection,
        private readonly SectionKinds $kinds,
        private readonly iterable $providers = [],
        private readonly ?ActivityLog $activity = null,
        private readonly ?Campaigns $campaigns = null,
        private readonly ?Pictures $pictures = null,
    ) {
    }

    /** @return list<LandingTemplate> the built-in templates that suit a page (every one when none is given) */
    public function builtIn(?LandingContext $page = null): array
    {
        $templates = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->templates() as $template) {
                if (($page === null || $template->suitsPage($page)) && !isset($templates[$template->key])) {
                    $templates[$template->key] = $template;
                }
            }
        }

        return array_values($templates);
    }

    /** @return list<LandingTemplate> the saved templates, by name */
    public function saved(): array
    {
        return array_map($this->template(...), $this->connection->fetchAllAssociative('SELECT * FROM ' . self::TABLE . ' ORDER BY lower(name), id'));
    }

    /** @return list<LandingTemplate> the templates a page may start from: the built-in ones, then the saved ones when the reader may see them */
    public function available(LandingContext $page, bool $withSaved): array
    {
        $saved = $withSaved ? array_values(array_filter($this->saved(), static fn(LandingTemplate $template): bool => $template->suitsPage($page))) : [];

        return [...$this->builtIn($page), ...$saved];
    }

    /** A template by its key: a built-in one's, or saved: and a saved one's reference. */
    public function get(string $key): ?LandingTemplate
    {
        if (str_starts_with($key, self::SAVED)) {
            $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE reference = :reference', ['reference' => substr($key, strlen(self::SAVED))]);

            return $row === false ? null : $this->template($row);
        }
        foreach ($this->builtIn() as $template) {
            if ($template->key === $key) {
                return $template;
            }
        }

        return null;
    }

    /**
     * A template made ready for a page. Kinds the site does not have or that do not belong on the page are left out, and
     * so is a second of a kind a page holds once; each is named in words. The offers sections take the page's offers.
     */
    public function apply(LandingTemplate $template, LandingContext $page): TemplateApplied
    {
        $sections = [];
        $skipped = [];
        $seen = [];
        foreach ($template->sections as $spec) {
            $kind = $this->kinds->get($spec['kind']);
            if ($kind === null || !$kind->appliesTo($page) || ($kind->once() && isset($seen[$kind->key()]))) {
                $skipped[] = $kind?->label() ?? $spec['kind'];
                continue;
            }
            $data = ($spec['data'] ?? []) + $kind->defaults();
            if (array_key_exists('offers', $data) && $data['offers'] === []) {
                $data['offers'] = array_map(static fn($offer): string => $offer->key(), $page->offers);
            }
            try {
                $data = $kind->normalise($data, $page);
            } catch (\InvalidArgumentException) {
                $data = $kind->normalise($kind->defaults(), $page);
            }
            $seen[$kind->key()] = true;
            $width = $kind->alwaysFull() || ($spec['width'] ?? 'full') !== 'half' ? 'full' : 'half';
            $shownWhen = $page->isCampaignPage() && array_key_exists($spec['shown_when'] ?? 'always', LandingSection::SHOWN_WHEN) ? (string) ($spec['shown_when'] ?? 'always') : 'always';
            $section = ['kind' => $kind->key(), 'width' => $width, 'shown_when' => $shownWhen, 'enabled' => $spec['enabled'] ?? true, 'data' => $data];
            // The hero is always first.
            if ($kind->key() === 'hero') {
                array_unshift($sections, $section);
            } else {
                $sections[] = $section;
            }
        }

        return new TemplateApplied($sections, array_values(array_unique($skipped)));
    }

    /**
     * Saves a page's sections as a template, with their copy, widths and settings and without the offers chosen.
     *
     * @param list<array<string,mixed>> $sections a version's sections (LandingVersions::snapshot())
     * @throws MarketingRefused
     */
    public function save(string $name, string $note, array $sections, ?Actor $actor = null): LandingTemplate
    {
        $errors = [];
        try {
            $name = LandingCopy::line($name, 80, 'The name', 'name');
            if ($name === '') {
                $errors['name'] = 'Type a name for the template, up to 80 characters.';
            } elseif ($this->connection->fetchOne('SELECT 1 FROM ' . self::TABLE . ' WHERE lower(name) = lower(:name)', ['name' => $name]) !== false) {
                $errors['name'] = 'A template called ' . $name . ' is already saved: choose another name.';
            }
        } catch (CopyRefused $refused) {
            $errors['name'] = $refused->getMessage();
        }
        try {
            $note = LandingCopy::line($note, 200, 'The note', 'note');
        } catch (CopyRefused $refused) {
            $errors['note'] = $refused->getMessage();
        }
        if ($errors !== []) {
            throw new MarketingRefused($errors);
        }
        $kept = [];
        foreach ($sections as $section) {
            $kept[] = ['kind' => Rows::text($section['kind'] ?? ''), 'width' => Rows::text($section['width'] ?? 'full'), 'shown_when' => Rows::text($section['shown_when'] ?? 'always'),
                'enabled' => Rows::bool($section['enabled'] ?? true), 'data' => self::withoutOffers(is_array($section['data'] ?? null) ? $section['data'] : [])];
        }
        $reference = Rows::reference();
        $now = Rows::sql($this->campaigns?->now() ?? new \DateTimeImmutable());
        $this->connection->executeStatement('INSERT INTO ' . self::TABLE . ' (reference, name, note, sections, made_by, made_at, updated_at) VALUES (:reference, :name, :note, CAST(:sections AS jsonb), :by, :now, :now)',
            ['reference' => $reference, 'name' => $name, 'note' => $note, 'sections' => json_encode($kept, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'by' => $actor?->id, 'now' => $now]);
        $template = $this->get(self::SAVED . $reference) ?? throw MarketingRefused::because('The template could not be read back.');
        $this->activity?->record('marketing.landing_template.saved', ['template' => $reference, 'name' => $name, 'sections' => count($kept)], LandingPages::SUBJECT_TEMPLATE, $reference, $actor);

        return $template;
    }

    /** Deletes a saved template: templates are not history. A picture only it named goes too. */
    public function delete(string $reference, ?Actor $actor = null): void
    {
        $template = $this->get(self::SAVED . $reference) ?? throw MarketingRefused::because('There is no such template.');
        $this->connection->delete(self::TABLE, ['reference' => $template->reference]);
        foreach ($template->sections as $section) {
            foreach (LandingPages::uploadedPictures($section['data'] ?? []) as $name) {
                $this->pictures?->removeUnused($name);
            }
        }
        $this->activity?->record('marketing.landing_template.deleted', ['template' => $template->reference, 'name' => $template->name], LandingPages::SUBJECT_TEMPLATE, (string) $template->reference, $actor);
    }

    /**
     * A section's data without the offers chosen: an offers list emptied, a single offer cleared.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function withoutOffers(array $data): array
    {
        if (array_key_exists('offers', $data)) {
            $data['offers'] = [];
        }
        if (array_key_exists('offer', $data)) {
            $data['offer'] = '';
        }
        $picture = $data['picture'] ?? null;
        if (is_array($picture) && ($picture['source'] ?? '') === OfferPicture::KEY) {
            $data['picture'] = ['source' => OfferPicture::KEY, 'ref' => ''];
        }

        return $data;
    }

    /** @param array<string,mixed> $row */
    private function template(array $row): LandingTemplate
    {
        $sections = json_decode(Rows::text($row['sections']), true);
        $kept = [];
        foreach (is_array($sections) ? $sections : [] as $section) {
            if (is_array($section) && is_string($section['kind'] ?? null)) {
                $kept[] = ['kind' => $section['kind'], 'width' => Rows::text($section['width'] ?? 'full'), 'shown_when' => Rows::text($section['shown_when'] ?? 'always'),
                    'enabled' => Rows::bool($section['enabled'] ?? true), 'data' => is_array($section['data'] ?? null) ? $section['data'] : []];
            }
        }

        return new LandingTemplate(self::SAVED . Rows::text($row['reference']), Rows::text($row['name']), Rows::text($row['note']), Rows::text($row['suits']), $kept, false,
            Rows::text($row['reference']), Rows::nullableText($row['made_by']));
    }
}
