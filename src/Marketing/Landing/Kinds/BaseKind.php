<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing\Kinds;

use CattoPHP\Marketing\Destination;
use CattoPHP\Marketing\Landing\CopyRefused;
use CattoPHP\Marketing\Landing\LandingContext;
use CattoPHP\Marketing\Landing\LandingCopy;
use CattoPHP\Marketing\Landing\LandingVisit;
use CattoPHP\Marketing\Landing\SectionKind;

/**
 * What most section kinds share: a heading and words, a picture written as {source, ref}, offers written as their keys, a
 * flag from a tick box, and the usual answers (any page, no picture, nothing to load). Each of CattoPHP's kinds extends it;
 * a project's kind may too, or implement SectionKind alone.
 */
abstract class BaseKind implements SectionKind
{
    /** A picture source's key and a picture's reference, as a section stores them. */
    public const SOURCE = '/^[a-z][a-z0-9_]{0,39}$/';
    public const REF = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,199}$/';

    /** An offer's key: its type and reference. */
    public const OFFER = '/^[a-z0-9][a-z0-9_-]{0,39}:[^\s:][^\s]{0,119}$/';

    public function alwaysFull(): bool
    {
        return false;
    }

    public function appliesTo(LandingContext $page): bool
    {
        return true;
    }

    public function pictures(array $data): array
    {
        return [];
    }

    public function form(LandingContext $page): array
    {
        return [];
    }

    public function formTemplate(): string
    {
        return '@CattoPHP/marketing/landing/forms/' . $this->key() . '.html.twig';
    }

    public function template(): string
    {
        return '@CattoPHP/marketing/landing/sections/' . $this->key() . '.html.twig';
    }

    public function needs(): array
    {
        return [];
    }

    public function summary(array $data, LandingContext $page): string
    {
        $heading = $this->typedHeading($data);

        return $heading !== '' ? '"' . $heading . '"' : $this->label();
    }

    /** @param array<string,mixed> $input */
    protected function heading(array $input): string
    {
        return LandingCopy::line($input['heading'] ?? '', 120, 'The heading', 'heading');
    }

    /** @param array<string,mixed> $input */
    protected function text(array $input, int $max = 1000): string
    {
        return LandingCopy::prose($input['text'] ?? '', $max, 'The introduction', 'text');
    }

    /** @param array<string,mixed> $data */
    protected function typedHeading(array $data): string
    {
        return trim(is_string($data['heading'] ?? null) ? $data['heading'] : '');
    }

    /**
     * The heading and paragraphs every section's card draws.
     *
     * @param array<string,mixed> $data
     * @return array{heading:string,paragraphs:list<list<string>>}
     */
    protected function common(array $data): array
    {
        $heading = $this->typedHeading($data);

        return ['heading' => $heading !== '' ? $heading : $this->defaultHeading(), 'paragraphs' => LandingCopy::paragraphs(is_string($data['text'] ?? null) ? $data['text'] : '')];
    }

    /**
     * A picture as posted ({source, ref}, a source of none or nothing for no picture) or stored.
     *
     * @return array{source:string,ref:string}|null
     */
    protected static function picture(mixed $value, string $field, string $label): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $source = is_string($value['source'] ?? null) ? trim($value['source']) : '';
        if ($source === '' || $source === 'none') {
            return null;
        }
        $ref = is_scalar($value['ref'] ?? null) ? trim((string) $value['ref']) : '';
        if (preg_match(self::SOURCE, $source) !== 1 || ($ref !== '' && preg_match(self::REF, $ref) !== 1)) {
            throw new CopyRefused($field, 'Choose ' . $label . ' from the choices.');
        }

        return ['source' => $source, 'ref' => $ref];
    }

    /** @return list<string> offers' keys as posted or stored, in order, each once */
    protected static function offers(mixed $value, int $most, string $field): array
    {
        $keys = [];
        foreach (is_array($value) ? $value : [] as $key) {
            if (!is_string($key) || trim($key) === '') {
                continue;
            }
            $key = trim($key);
            if (preg_match(self::OFFER, $key) !== 1) {
                throw new CopyRefused($field, 'Choose the offers from the list.');
            }
            $keys[$key] = $key;
        }
        if (count($keys) > $most) {
            throw new CopyRefused($field, sprintf('A section shows at most %d offers.', $most));
        }

        return array_values($keys);
    }

    protected static function offerKey(mixed $value, string $field): string
    {
        $key = is_string($value) ? trim($value) : '';
        if ($key !== '' && preg_match(self::OFFER, $key) !== 1) {
            throw new CopyRefused($field, 'Choose an offer from the list.');
        }

        return $key;
    }

    /** A path on the site as typed, or '' for none. */
    protected static function path(mixed $value, string $field): string
    {
        $path = is_string($value) ? trim($value) : '';
        if ($path !== '' && (!Destination::isPath($path) || Destination::problem($path) !== null)) {
            throw new CopyRefused($field, 'Type a path on the site starting with /, such as /offers.');
        }

        return $path;
    }

    protected static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'on';
    }

    /** A part of the page a button leads to: a section kind's key, or none. */
    protected static function target(mixed $value, string $field): string
    {
        $target = is_string($value) ? trim($value) : '';
        if ($target !== '' && $target !== 'none' && preg_match(self::SOURCE, $target) !== 1) {
            throw new CopyRefused($field, 'Choose where the button leads.');
        }

        return $target;
    }

    protected static function plural(int $n, string $one, string $many): string
    {
        return $n . ' ' . ($n === 1 ? $one : $many);
    }

    /**
     * The data's list under a key, or none.
     *
     * @param array<string,mixed> $data
     * @return list<mixed>
     */
    protected static function items(array $data, string $key = 'items'): array
    {
        return is_array($data[$key] ?? null) ? array_values($data[$key]) : [];
    }

    public function view(array $data, LandingContext $page, LandingVisit $visit): ?array
    {
        return $this->common($data);
    }
}
