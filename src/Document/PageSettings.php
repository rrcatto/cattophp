<?php

declare(strict_types=1);

namespace CattoPHP\Document;

/**
 * A document template version's page: its size, orientation and margins, from a controlled list. They
 * are structured settings rather than CSS a template author has to get right, and the renderer turns
 * them into the one page box both the PDF and the on-screen preview use.
 */
final class PageSettings
{
    /** Portrait width and height in millimetres. */
    public const SIZES = ['A4' => [210, 297], 'A5' => [148, 210], 'Letter' => [216, 279], 'Legal' => [216, 356]];
    public const ORIENTATIONS = ['portrait' => 'Portrait', 'landscape' => 'Landscape'];
    public const MAX_MARGIN_MM = 50;
    private const MARGINS = ['margin_top_mm' => 'Top margin', 'margin_right_mm' => 'Right margin', 'margin_bottom_mm' => 'Bottom margin', 'margin_left_mm' => 'Left margin'];

    public function __construct(
        public readonly string $size,
        public readonly string $orientation,
        public readonly int $marginTop,
        public readonly int $marginRight,
        public readonly int $marginBottom,
        public readonly int $marginLeft,
    ) {
        $errors = self::problems($size, $orientation, [$marginTop, $marginRight, $marginBottom, $marginLeft]);
        if ($errors !== []) {
            throw new TemplateValidationException($errors);
        }
    }

    /**
     * Settings from a form or a stored row. Every problem is reported together.
     *
     * @param array<string,mixed> $input
     */
    public static function fromInput(array $input): self
    {
        $size = trim((string) ($input['page_size'] ?? ''));
        $orientation = trim((string) ($input['page_orientation'] ?? ''));
        $margins = [];
        $errors = [];
        foreach (self::MARGINS as $field => $label) {
            $value = trim((string) ($input[$field] ?? ''));
            if (preg_match('/^\d{1,3}$/D', $value) !== 1) {
                $errors[] = $label . ' must be a whole number of millimetres from 0 to ' . self::MAX_MARGIN_MM . '.';
                $margins[] = 0;
                continue;
            }
            $margins[] = (int) $value;
        }
        $errors = [...$errors, ...self::problems($size, $orientation, $margins)];
        if ($errors !== []) {
            throw new TemplateValidationException($errors);
        }
        return new self($size, $orientation, ...$margins);
    }

    /** @return array<string,string|int> the stored and posted form */
    public function toArray(): array
    {
        return [
            'page_size' => $this->size,
            'page_orientation' => $this->orientation,
            'margin_top_mm' => $this->marginTop,
            'margin_right_mm' => $this->marginRight,
            'margin_bottom_mm' => $this->marginBottom,
            'margin_left_mm' => $this->marginLeft,
        ];
    }

    public function widthMm(): int
    {
        [$width, $height] = self::SIZES[$this->size];
        return $this->orientation === 'landscape' ? $height : $width;
    }

    public function heightMm(): int
    {
        [$width, $height] = self::SIZES[$this->size];
        return $this->orientation === 'landscape' ? $width : $height;
    }

    /** The page as CSS pixels at 96 per inch, for the preview frame. */
    public function widthPx(): int
    {
        return (int) round($this->widthMm() * 96 / 25.4);
    }

    public function heightPx(): int
    {
        return (int) round($this->heightMm() * 96 / 25.4);
    }

    public function label(): string
    {
        return $this->size . ' ' . strtolower(self::ORIENTATIONS[$this->orientation])
            . sprintf(', margins %d/%d/%d/%d mm', $this->marginTop, $this->marginRight, $this->marginBottom, $this->marginLeft);
    }

    /**
     * The page box. @page applies to the PDF; the screen rule gives the preview the same printable
     * area, since a browser ignores @page margins on screen. The PDF is rendered for print, so the
     * screen rule never adds to its margins.
     */
    public function css(): string
    {
        $margins = sprintf('%dmm %dmm %dmm %dmm', $this->marginTop, $this->marginRight, $this->marginBottom, $this->marginLeft);
        return sprintf('@page{size:%s %s;margin:%s}', $this->size, $this->orientation, $margins)
            . sprintf('@media screen{html{background:#fff}body{margin:0;padding:%s;box-sizing:border-box;min-height:%dmm}}', $margins, $this->heightMm());
    }

    /**
     * @param list<int> $margins top, right, bottom, left
     * @return list<string>
     */
    private static function problems(string $size, string $orientation, array $margins): array
    {
        $errors = [];
        if (!isset(self::SIZES[$size])) {
            $errors[] = 'Unsupported page size "' . $size . '". Choose ' . implode(', ', array_keys(self::SIZES)) . '.';
        }
        if (!isset(self::ORIENTATIONS[$orientation])) {
            $errors[] = 'Unsupported orientation "' . $orientation . '". Choose portrait or landscape.';
        }
        foreach (array_values(self::MARGINS) as $index => $label) {
            $margin = $margins[$index] ?? 0;
            if ($margin < 0 || $margin > self::MAX_MARGIN_MM) {
                $errors[] = $label . ' must be from 0 to ' . self::MAX_MARGIN_MM . ' mm.';
            }
        }
        return $errors;
    }
}
