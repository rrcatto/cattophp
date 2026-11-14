<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Http;

use CattoPHP\Http\CsvDownload;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A list downloaded as a spreadsheet file: read by a spreadsheet as written, never running a formula, never cached. */
final class CsvDownloadTest extends TestCase
{
    private static function body(CsvDownload $download): string
    {
        ob_start();
        try {
            $download->sendContent();
        } finally {
            $body = (string) ob_get_clean();
        }

        return $body;
    }

    public function testItIsAStreamedAttachmentNamedForWhatItHoldsAndTheDayNeverKept(): void
    {
        $download = new CsvDownload('Campaigns', ['Campaign', 'Clicks'], [], new DateTimeImmutable('2026-11-13 18:00'));
        $download->prepare(Request::create('https://example.com/marketing/campaigns.csv'));
        self::assertInstanceOf(StreamedResponse::class, $download);
        self::assertSame(200, $download->getStatusCode());
        self::assertSame('text/csv; charset=UTF-8', $download->headers->get('Content-Type'));
        self::assertSame('attachment; filename=campaigns-2026-11-13.csv', $download->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
        self::assertFalse($download->isCacheable());
    }

    public function testTheFilesNameIsMadeSafe(): void
    {
        $day = new DateTimeImmutable('2026-01-05');
        self::assertSame('spring-sale-clicks-2026-01-05.csv', CsvDownload::fileName('Spring Sale: clicks', $day));
        self::assertSame('etc-passwd-2026-01-05.csv', CsvDownload::fileName('../../etc/passwd', $day), 'No folder, no dots.');
        self::assertSame('caf-visitors-2026-01-05.csv', CsvDownload::fileName("Café\r\nvisitors", $day), 'No line break to forge a header.');
        self::assertSame('download-2026-01-05.csv', CsvDownload::fileName('***', $day));
        self::assertSame(str_repeat('a', 60) . '-2026-01-05.csv', CsvDownload::fileName(str_repeat('a', 80), $day));
        self::assertSame(str_repeat('a', 59) . '-2026-01-05.csv', CsvDownload::fileName(str_repeat('a', 59) . ' bcd', $day), 'Cut short, it never ends on a hyphen.');
    }

    public function testItIsUtf8WithAByteOrderMarkAndOneLineARow(): void
    {
        $rows = (static function (): \Generator {
            yield ['Ünïcode Événement', 1200, 'R1 200,50'];
            yield ['Says "hello", twice', 0, "two\nlines"];
        })();
        $body = self::body(new CsvDownload('campaigns', ['Campaign', 'Clicks', 'Spend'], $rows, new DateTimeImmutable('2026-11-13')));
        self::assertStringStartsWith("\xEF\xBB\xBF", $body);
        self::assertSame(
            "Campaign,Clicks,Spend\r\n\"Ünïcode Événement\",1200,\"R1 200,50\"\r\n\"Says \"\"hello\"\", twice\",0,\"two\nlines\"\r\n",
            substr($body, 3),
        );
        self::assertTrue(mb_check_encoding($body, 'UTF-8'));
    }

    public function testWordsThatWouldRunAsAFormulaAreWrittenAsWordsAndNumbersStayNumbers(): void
    {
        $body = substr(self::body(new CsvDownload('visitors', ['Name', 'Note'], [
            ['=HYPERLINK("http://example.com","Open")', '+27 21 555 0100'],
            ['-2+3', '@SUM(A1:A2)'],
            ["\tTabbed", "\rReturned"],
            [-5, '-12.50'],
            [3.25, 0.1 + 0.2],
            [true, false],
            [null, new DateTimeImmutable('2026-11-13 14:05:09')],
            ['plain words', 'a=b'],
        ], new DateTimeImmutable('2026-11-13'))), 3);
        $lines = explode("\r\n", rtrim($body, "\r\n"));
        self::assertSame([
            'Name,Note',
            "\"'=HYPERLINK(\"\"http://example.com\"\",\"\"Open\"\")\",\"'+27 21 555 0100\"",
            "'-2+3,'@SUM(A1:A2)",
            "\"'\tTabbed\",\"'\rReturned\"",
            '-5,-12.50',
            '3.25,0.3',
            'Yes,No',
            ',"2026-11-13 14:05"',
            '"plain words",a=b',
        ], $lines);
    }

    public function testACellHoldsOnlyWhatASpreadsheetCanShow(): void
    {
        self::assertSame(1234.5, (float) CsvDownload::cell(1234.5));
        self::assertSame('0.0000001', CsvDownload::cell(1.0E-7), 'Never an exponent.');
        self::assertSame("'=1+1", CsvDownload::cell(new class implements \Stringable {
            public function __toString(): string
            {
                return '=1+1';
            }
        }));
        foreach ([['a list'], new \stdClass(), INF] as $value) {
            try {
                CsvDownload::cell($value);
                self::fail('Accepted ' . get_debug_type($value));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testItNeedsAHeaderRow(): void
    {
        $this->expectExceptionMessage('A CSV download needs a header row');
        new CsvDownload('people', [], []);
    }

    public function testALongListIsWrittenAsItComes(): void
    {
        $made = 0;
        $rows = (static function () use (&$made): \Generator {
            for ($row = 1; $row <= 1200; $row++) {
                $made++;
                yield ['Row ' . $row, $row];
            }
        })();
        $download = new CsvDownload('rows', ['Name', 'Number'], $rows, new DateTimeImmutable('2026-11-13'));
        self::assertSame(0, $made, 'Nothing is read until the answer is sent.');
        $body = self::body($download);
        self::assertSame(1200, $made);
        self::assertSame(1201, substr_count($body, "\r\n"));
        self::assertStringEndsWith("\"Row 1200\",1200\r\n", $body);
    }
}
