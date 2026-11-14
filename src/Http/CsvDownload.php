<?php

declare(strict_types=1);

namespace CattoPHP\Http;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;
use Stringable;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A list downloaded as a spreadsheet file (CSV), the answer a controller gives for "Download as CSV": a header row and
 * the rows, written as they come so a long list never has to be held at once. UTF-8 with a byte-order mark, so a
 * spreadsheet reads its accents and currency signs; saved under a safe name carrying the day ("campaigns-2026-11-13.csv");
 * never kept by a browser or a cache in between. A cell of words beginning with =, +, -, @, a tab or a carriage return is
 * written after an apostrophe, so a spreadsheet shows it as words and never runs it as a formula; a number stays a number
 * (a negative one too), true and false are Yes and No, a moment is written 2026-11-13 14:05.
 */
final class CsvDownload extends StreamedResponse
{
    /** What a spreadsheet takes as the first characters of a formula. */
    private const FORMULA_START = ['=', '+', '-', '@', "\t", "\r"];

    /** Rows written between each push to the browser. */
    private const FLUSH_EVERY = 500;

    /**
     * @param string $name What the file holds, in a word or two ("campaigns"): made safe for a file name, the day added.
     * @param list<string> $header Each column's heading.
     * @param iterable<array<mixed>> $rows Each row's cells in the header's order: words, numbers, true or false, a moment,
     *                                    or nothing.
     * @param DateTimeInterface|null $day The day the name carries; today in the installation's zone if not given.
     */
    public function __construct(string $name, array $header, iterable $rows, ?DateTimeInterface $day = null)
    {
        if ($header === []) {
            throw new InvalidArgumentException('A CSV download needs a header row: a list of each column\'s heading in words.');
        }
        parent::__construct(static function () use ($header, $rows): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                throw new RuntimeException('The CSV download could not be written.');
            }
            fwrite($out, "\xEF\xBB\xBF");
            self::write($out, $header);
            $written = 0;
            foreach ($rows as $row) {
                self::write($out, array_values($row));
                if (++$written % self::FLUSH_EVERY === 0) {
                    fflush($out);
                    flush();
                }
            }
            fclose($out);
        }, self::HTTP_OK, ['Content-Type' => 'text/csv; charset=UTF-8']);
        $file = self::fileName($name, $day ?? new DateTimeImmutable());
        $this->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file, $file));
        $this->headers->set('Cache-Control', 'no-store');
    }

    /**
     * The file's name: what it holds in lower-case letters, digits and hyphens (anything else, an accented letter too,
     * becomes a hyphen), at most 60 of them, then the day ("campaigns-2026-11-13.csv").
     */
    public static function fileName(string $name, DateTimeInterface $day): string
    {
        $safe = trim(substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-'), 0, 60), '-');

        return ($safe === '' ? 'download' : $safe) . '-' . $day->format('Y-m-d') . '.csv';
    }

    /** A cell as the file writes it: words made safe from being read as a formula, a number as it is. */
    public static function cell(mixed $value): string|int
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'Yes' : 'No',
            is_int($value) => $value,
            is_float($value) => is_finite($value) ? self::decimal($value) : throw new InvalidArgumentException('A CSV cell\'s number is finite.'),
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i'),
            is_string($value), $value instanceof Stringable => self::words((string) $value),
            default => throw new InvalidArgumentException('A CSV cell holds words, a number, true or false, a moment or nothing, never ' . get_debug_type($value) . '.'),
        };
    }

    /**
     * @param resource $out
     * @param list<mixed> $cells
     */
    private static function write($out, array $cells): void
    {
        fputcsv($out, array_map(self::cell(...), $cells), ',', '"', '', "\r\n");
    }

    /** Words a spreadsheet would run as a formula written after an apostrophe; a negative number in words stays a number. */
    private static function words(string $value): string
    {
        if ($value === '' || !in_array($value[0], self::FORMULA_START, true) || preg_match('/^-\d+(\.\d+)?$/', $value) === 1) {
            return $value;
        }

        return "'" . $value;
    }

    /** A number with a fraction written plainly, never with an exponent: 0.1, 1234.5. */
    private static function decimal(float $value): string
    {
        $written = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');

        return $written === '-0' ? '0' : $written;
    }
}
