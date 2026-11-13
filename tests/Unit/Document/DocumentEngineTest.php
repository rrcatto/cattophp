<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Document;

use CattoPHP\Document\CollectionDefinition;
use CattoPHP\Document\DocumentDataException;
use CattoPHP\Document\DocumentImages;
use CattoPHP\Document\DocumentRenderer;
use CattoPHP\Document\PageSettings;
use CattoPHP\Document\PdfRenderer;
use CattoPHP\Document\PlaceholderDefinition;
use CattoPHP\Document\PlaceholderKind;
use CattoPHP\Document\PlaceholderSet;
use CattoPHP\Document\TemplateParser;
use CattoPHP\Document\TemplateValidationException;
use CattoPHP\Document\TemplateValidator;
use CattoPHP\Document\ValueFormatter;
use CattoPHP\Tests\Support\Foundations;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The document engine without a database: the template language and its errors, placeholders, repeating and conditional
 * blocks, values written by their kind and escaped, the HTML and CSS rules, page settings, stored pictures and PDFs,
 * ported from cattolms's tests with example placeholders of its own.
 */
final class DocumentEngineTest extends TestCase
{
    private const INVOICE = 'invoice';
    private const CERTIFICATE = 'certificate';

    private DocumentRenderer $renderer;
    private TemplateValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new TemplateValidator();
        $this->renderer = new DocumentRenderer($this->validator, new ValueFormatter(new DateTimeZone('Africa/Johannesburg'), 'en_ZA'));
    }

    public function testTheLanguageParsesPlaceholdersRepeatingAndConditionalBlocks(): void
    {
        $tree = (new TemplateParser())->parse("<p>{{ invoice.number }}</p>\n{{#each items}}{{ item.description }}{{#if item.discount}}-{{else}}={{/if}}{{/each}}");
        self::assertSame(['text', 'value', 'text', 'each'], array_column($tree, 'type'));
        self::assertSame('invoice.number', $tree[1]['path']);
        self::assertSame(2, $tree[3]['line']);
        self::assertSame(['value', 'if'], array_column($tree[3]['children'], 'type'));
        self::assertSame('else', array_key_first(array_slice($tree[3]['children'][1], -1, 1, true)));
    }

    /** @return iterable<string,array{string,string}> */
    public static function malformed(): iterable
    {
        yield 'unclosed placeholder' => ['<p>{{ invoice.number</p>', 'never closed with }}'];
        yield 'unclosed repeating block' => ["{{#each items}}\n{{ item.description }}", '{{#each …}} opened on line 1 is never closed'];
        yield 'unclosed condition' => ['{{#if invoice.discount}}x', '{{#if …}} opened on line 1 is never closed'];
        yield 'mismatched close' => ['{{#each items}}{{/if}}', 'does not match the {{#each …}}'];
        yield 'close with nothing open' => ['{{/each}}', 'never opened'];
        yield 'else outside a condition' => ['{{else}}', 'not inside an {{#if …}}'];
        yield 'second else' => ['{{#if invoice.discount}}{{else}}{{else}}{{/if}}', 'second {{else}}'];
        yield 'filter' => ['{{ customer.billing_name|raw }}', 'Filters such as'];
        yield 'twig tag' => ['{% include "x.twig" %}', 'Twig tags'];
        yield 'twig comment' => ['{# note #}', 'Twig tags'];
        yield 'expression' => ['{{ 7*7 }}', 'Unsupported expression'];
        yield 'method call' => ['{{ invoice.number() }}', 'Unsupported expression'];
        yield 'unknown block' => ['{{#with customer}}{{/with}}', 'Unknown block'];
        yield 'bare name' => ['{{ number }}', 'have a group and a name'];
        yield 'multi-line placeholder' => ["{{ invoice.\nnumber }}", 'open and close on one line'];
    }

    #[DataProvider('malformed')]
    public function testMalformedSyntaxIsRejectedWithItsLine(string $html, string $message): void
    {
        $errors = $this->errors(self::INVOICE, $html);
        self::assertNotSame([], $errors);
        self::assertStringContainsString($message, implode(' ', $errors));
    }

    public function testAnUnknownPlaceholderIsAnErrorWithASuggestionNeverABlank(): void
    {
        $errors = $this->errors(self::INVOICE, "<p>x</p>\n<p>{{ invoice.nubmer }}</p>");
        self::assertCount(1, $errors);
        self::assertStringContainsString('Unknown placeholder {{ invoice.nubmer }} on line 2', $errors[0]);
        self::assertStringContainsString('did you mean {{ invoice.number }}', $errors[0]);

        // A placeholder of another document type is unknown here.
        self::assertStringContainsString('not a certificate placeholder', implode(' ', $this->errors(self::CERTIFICATE, '{{ invoice.number }}')));
        // Unregistered object traversal is refused too.
        self::assertNotSame([], $this->errors(self::INVOICE, '{{ customer.user.password_hash }}'));
    }

    public function testRepeatingFieldsAreOnlyKnownInsideTheirBlockAndBlocksDoNotNest(): void
    {
        self::assertStringContainsString('only available inside {{#each items}}', implode(' ', $this->errors(self::INVOICE, '{{ item.description }}')));
        self::assertStringContainsString('cannot be nested', implode(' ', $this->errors(self::INVOICE, '{{#each items}}{{#each items}}{{/each}}{{/each}}')));
        self::assertStringContainsString('has no repeating data', implode(' ', $this->errors(self::CERTIFICATE, '{{#each items}}{{/each}}')));
        self::assertStringContainsString('Unknown condition', implode(' ', $this->errors(self::INVOICE, '{{#if invoice.colour}}x{{/if}}')));
        self::assertSame([], $this->errors(self::INVOICE, '{{#if items}}{{#each items}}{{ item.paid }}{{/each}}{{else}}None{{/if}}'));
    }

    /** @return iterable<string,array{string,string}> */
    public static function unsafeHtml(): iterable
    {
        yield 'script' => ['<script>alert(1)</script>', '<script> elements are not allowed'];
        yield 'svg script' => ['<svg><script>alert(1)</script></svg>', '<script> elements are not allowed'];
        yield 'style element' => ['<style>p{}</style>', 'put styles in the CSS field'];
        yield 'iframe' => ['<iframe src="https://example.com"></iframe>', '<iframe> elements'];
        yield 'form' => ['<form action="/x"><input></form>', '<form> elements'];
        yield 'event handler' => ['<img src="data:image/png;base64,AA" onerror="alert(1)">', 'Event handler attributes'];
        yield 'svg event handler' => ['<svg onload="alert(1)"></svg>', 'Event handler attributes'];
        yield 'javascript link' => ['<a href="javascript:alert(1)">x</a>', 'javascript: and vbscript:'];
        yield 'encoded javascript link' => ['<a href="jav&#x61;script:alert(1)">x</a>', 'javascript: and vbscript:'];
        yield 'remote image' => ['<img src="https://example.com/logo.png">', 'embedded data: images'];
        yield 'server file image' => ['<img src="file:///etc/passwd">', 'embedded data: images'];
        yield 'remote svg use' => ['<svg><use href="https://example.com/a.svg#x"></use></svg>', 'embedded data: images'];
        yield 'data html link' => ['<a href="data:text/html,x">x</a>', 'Links may only go to'];
        yield 'placeholder in style' => ['<p style="color:{{ business.name }}">x</p>', 'only allowed in class, title, alt'];
        yield 'placeholder in src' => ['<img src="{{ business.website }}">', 'only allowed in class, title, alt'];
        yield 'non-url placeholder as href' => ['<a href="{{ customer.billing_name }}">x</a>', 'not a web address'];
        yield 'block in attribute' => ['<p class="{{#if invoice.discount}}a{{/if}}">x</p>', 'cannot go inside an attribute'];
        yield 'block as attribute' => ['<p {{#if invoice.discount}}hidden{{/if}}>x</p>', 'cannot stand in for an attribute'];
        yield 'document wrapper' => ['<html><body>x</body></html>', 'remove the <html> tag'];
        yield 'remote css in style attribute' => ['<div style="background:url(https://example.com/x.png)">x</div>', 'embedded image'];
    }

    #[DataProvider('unsafeHtml')]
    public function testExecutableAndExternalHtmlIsRefused(string $html, string $message): void
    {
        self::assertStringContainsString($message, implode(' ', $this->errors(self::INVOICE, $html)));
    }

    public function testOrdinaryDocumentMarkupIsAllowed(): void
    {
        $html = '<section class="sheet {{ invoice.currency }}"><h1 title="{{ invoice.number }}">Invoice</h1>'
            . '<table><tr><td style="border:1px solid #000;page-break-inside:avoid">x</td></tr></table>'
            . '<img alt="{{ business.name }}" src="data:image/png;base64,iVBORw0KGgo=">'
            . '<svg viewBox="0 0 10 10"><use href="#mark"/></svg>'
            . '<a href="{{ business.website }}">site</a> <a href="https://example.com">x</a> <a href="mailto:a@example.com">m</a></section>';
        self::assertSame([], $this->errors(self::INVOICE, $html));
    }

    /** @return iterable<string,array{string,string}> */
    public static function unsafeCss(): iterable
    {
        yield 'import' => ['@import url("data:image/png,x");', '@import'];
        yield 'remote url' => ['body{background:url(https://example.com/bg.png)}', 'embedded image'];
        yield 'relative url' => ['body{background:url("bg.png")}', 'embedded image'];
        yield 'expression' => ['p{width:expression(alert(1))}', 'expression()'];
        yield 'javascript' => ['p{background:url(javascript:alert(1))}', 'javascript:'];
        yield 'behaviour' => ['p{behavior:url(x.htc)}', 'behavior'];
        yield 'escape' => ['p{background:\75rl(x)}', 'CSS escapes'];
        yield 'closing style' => ['p{} </style><script>', '"</"'];
        yield 'placeholder' => ['p{color:{{ business.name }}}', 'cannot contain placeholders'];
    }

    #[DataProvider('unsafeCss')]
    public function testUnsafeCssIsRefused(string $css, string $message): void
    {
        self::assertStringContainsString($message, implode(' ', $this->errors(self::INVOICE, '<p>x</p>', $css)));
    }

    public function testDocumentCssForPrintIsAllowed(): void
    {
        $css = "@page{size:A4}\nbody{font-family:\"DejaVu Serif\",serif}\n.page{page-break-after:always;background:url('data:image/svg+xml;base64,PHN2Zz4=') no-repeat}\ntable{border-collapse:collapse}";
        self::assertSame([], $this->errors(self::CERTIFICATE, '<p>{{ learner.name }}</p>', $css));
    }

    public function testPageSettingsAreAControlledList(): void
    {
        $landscape = PageSettings::fromInput(['page_size' => 'A4', 'page_orientation' => 'landscape', 'margin_top_mm' => '12', 'margin_right_mm' => '10', 'margin_bottom_mm' => '12', 'margin_left_mm' => '10']);
        self::assertSame([297, 210], [$landscape->widthMm(), $landscape->heightMm()]);
        self::assertStringContainsString('@page{size:A4 landscape;margin:12mm 10mm 12mm 10mm}', $landscape->css());
        $portrait = new PageSettings('Letter', 'portrait', 0, 0, 0, 0);
        self::assertSame([216, 279], [$portrait->widthMm(), $portrait->heightMm()]);
        self::assertSame(1123, (new PageSettings('A4', 'landscape', 0, 0, 0, 0))->widthPx(), 'A4 landscape is 1123 pixels wide at 96 to the inch.');

        try {
            PageSettings::fromInput(['page_size' => 'A3', 'page_orientation' => 'sideways', 'margin_top_mm' => '60', 'margin_right_mm' => '-1', 'margin_bottom_mm' => '5', 'margin_left_mm' => 'x']);
            self::fail('Invalid page settings are refused.');
        } catch (TemplateValidationException $invalid) {
            $all = implode(' ', $invalid->errors);
            foreach (['Unsupported page size "A3"', 'Unsupported orientation "sideways"', 'Top margin must be from 0 to 50', 'Right margin must be a whole number', 'Left margin must be a whole number'] as $expected) {
                self::assertStringContainsString($expected, $all);
            }
        }
    }

    public function testAmountsAreWrittenInTheInstallationsLocaleASetting(): void
    {
        $money = new PlaceholderDefinition('x.amount', '', PlaceholderKind::Money);
        $amount = ['minor' => 120000, 'currency' => 'ZAR'];
        $southAfrica = new Foundations(['APP_LOCALE' => 'en_ZA']);
        $british = new Foundations();
        $zone = new DateTimeZone('UTC');

        self::assertSame((new ValueFormatter($zone, 'en_ZA'))->money(120000, 'ZAR'), (new ValueFormatter($zone, null, $southAfrica->installation))->money(120000, 'ZAR'), 'APP_LOCALE from .env, through Settings.');
        self::assertSame((new ValueFormatter($zone, 'en_GB'))->money(120000, 'ZAR'), (new ValueFormatter($zone, null, $british->installation))->money(120000, 'ZAR'), 'None set: plain British English.');
        self::assertNotSame((new ValueFormatter($zone, 'en_ZA'))->money(120000, 'ZAR'), (new ValueFormatter($zone, 'en_GB'))->money(120000, 'ZAR'), 'The two are written differently, so the test tells them apart.');
        $british->settings->set('APP_LOCALE', 'en_ZA');
        self::assertSame((new ValueFormatter($zone, 'en_ZA'))->format($money, $amount), (new ValueFormatter($zone, null, $british->installation))->format($money, $amount), 'Changed on the Settings page: read each time.');
    }

    public function testValuesAreFormattedByKindAndAlwaysEscaped(): void
    {
        $formatter = new ValueFormatter(new DateTimeZone('Africa/Johannesburg'), 'en_ZA');
        $money = new PlaceholderDefinition('x.amount', '', PlaceholderKind::Money);
        self::assertStringContainsString('1', $formatter->format($money, ['minor' => 123456, 'currency' => 'ZAR']));
        self::assertMatchesRegularExpression('/1.234,56|1,234\.56/u', html_entity_decode($formatter->format($money, ['minor' => 123456, 'currency' => 'ZAR'])));
        self::assertNotNull($formatter->problem($money, 1234.56), 'A float is never accepted as money.');
        self::assertFalse($formatter->present($money, ['minor' => 0, 'currency' => 'ZAR']), 'A zero discount is absent in a condition.');

        $date = new PlaceholderDefinition('x.date', '', PlaceholderKind::Date);
        self::assertSame('6 October 2026', $formatter->format($date, '2026-10-06'));
        self::assertSame('7 October 2026', $formatter->format($date, new DateTimeImmutable('2026-10-06T23:30:00Z')), 'Dates are shown in the installation\'s time zone.');
        self::assertNotNull($formatter->problem($date, 'next Tuesday'));

        $address = new PlaceholderDefinition('x.address', '', PlaceholderKind::Address);
        self::assertSame('1 &lt;b&gt;Main&lt;/b&gt; Road<br>Cape Town', $formatter->format($address, ['1 <b>Main</b> Road', '', 'Cape Town']));
        $multi = new PlaceholderDefinition('x.note', '', PlaceholderKind::Multiline);
        self::assertSame("a &amp; b<br>\nc", $formatter->format($multi, "a & b\r\nc"));
        $text = new PlaceholderDefinition('x.text', '');
        self::assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $formatter->format($text, '<script>alert("x")</script>'));
        self::assertSame('', $formatter->format($text, null), 'An absent optional value prints nothing.');
        self::assertSame('84.50%', $formatter->format(new PlaceholderDefinition('x.p', '', PlaceholderKind::Percent), '84.50'));
        self::assertNotNull($formatter->problem(new PlaceholderDefinition('x.u', '', PlaceholderKind::Url), 'javascript:alert(1)'));
    }

    public function testRepeatingAndConditionalContentRenders(): void
    {
        $html = '{{#each items}}[{{ item.description }}:{{ item.paid }}{{#if item.discount}} less {{ item.discount }}{{/if}}]{{/each}}'
            . '{{#if items}}{{else}}No lines{{/if}}|{{#if customer.tax_number}}VAT {{ customer.tax_number }}{{else}}no VAT{{/if}}';
        $data = self::invoiceData();
        $body = $this->render(self::INVOICE, $html, '', $data)->body;
        self::assertSame(3, substr_count($body, '['), 'Every line renders.');
        self::assertStringContainsString('[Pruning roses:', $body);
        self::assertStringContainsString(' less ', $body, 'A line with a discount says so.');
        self::assertStringContainsString('|VAT 4000000001', $body);

        $data['items'] = [];
        $data['customer.tax_number'] = null;
        self::assertSame('No lines|no VAT', $this->render(self::INVOICE, $html, '', $data)->body, 'Zero lines and an absent optional value are handled.');
    }

    public function testRenderedValuesCannotInjectMarkupOrTemplateSyntax(): void
    {
        $data = self::certificateData();
        $data['learner.name'] = '<img src=x onerror=alert(1)> {{ business.name }} {% include "x" %}';
        $body = $this->render(self::CERTIFICATE, '<h1>{{ learner.name }}</h1>', '', $data)->body;
        self::assertSame('<h1>&lt;img src=x onerror=alert(1)&gt; {{ business.name }} {% include &quot;x&quot; %}</h1>', $body, 'A value is text: it is escaped and never parsed as a template.');
    }

    public function testDataMustFitThePlaceholders(): void
    {
        $data = self::certificateData();
        foreach ([
            'unknown key' => [['learner.password' => 'x'] + $data, 'learner.password is not a registered placeholder'],
            'missing required' => [array_diff_key($data, ['learner.name' => true]), 'learner.name is required'],
            'wrong kind' => [['course.completion_date' => 'soon'] + $data, 'course.completion_date must be a date'],
            'not a list' => [['items' => 'x'] + self::invoiceData(), 'items must be a list of entries'],
        ] as $case => [$values, $message]) {
            try {
                $this->render(str_contains($case, 'list') ? self::INVOICE : self::CERTIFICATE, '<p>x</p>', '', $values);
                self::fail('Bad data is refused: ' . $case);
            } catch (DocumentDataException $bad) {
                self::assertStringContainsString($message, $bad->getMessage(), $case);
            }
        }
        $free = self::invoiceData();
        $free['invoice.total'] = ['minor' => 0, 'currency' => 'ZAR'];
        self::assertStringContainsString('R', $this->render(self::INVOICE, '<p>{{ invoice.total }}</p>', '', $free)->body, 'A required amount may be zero: a free order still has a total.');
        $invoice = self::invoiceData();
        $invoice['items'][0]['colour'] = 'red';
        $this->expectException(DocumentDataException::class);
        $this->render(self::INVOICE, '<p>x</p>', '', $invoice);
    }

    public function testRenderedDocumentCarriesThePageBoxAndItsTemplateReference(): void
    {
        $settings = new PageSettings('A5', 'portrait', 10, 10, 10, 10);
        $document = $this->renderer->render(self::CERTIFICATE, 'Certificate', self::set(self::CERTIFICATE), '<p>{{ learner.name }}</p>', 'p{color:#111}', $settings, self::certificateData(), 7, 42, 3);
        self::assertStringStartsWith('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Certificate</title>', $document->html);
        self::assertStringContainsString('@page{size:A5 portrait;margin:10mm 10mm 10mm 10mm}', $document->html);
        self::assertStringContainsString('<style>p{color:#111}</style>', $document->html);
        self::assertSame(['document_type' => 'certificate', 'template_id' => 7, 'template_version_id' => 42, 'template_version_number' => 3], $document->reference());
        self::assertSame('certificate', $document->type);
    }

    public function testAStoredPictureIsEmbeddedAndAMissingOneIsAnError(): void
    {
        $sha = str_repeat('a', 64);
        $images = new class ($sha) implements DocumentImages {
            public function __construct(private readonly string $sha)
            {
            }

            public function dataUri(string $sha): ?string
            {
                return $sha === $this->sha ? 'data:image/png;base64,iVBORw0KGgo=' : null;
            }
        };
        $renderer = new DocumentRenderer(images: $images);
        $document = $renderer->render(self::CERTIFICATE, 'Certificate', self::set(self::CERTIFICATE), '<img alt="" src="asset:' . $sha . '">', '.x{background:url(asset:' . $sha . ')}', new PageSettings('A4', 'landscape', 0, 0, 0, 0), self::certificateData());
        self::assertStringContainsString('<img alt="" src="data:image/png;base64,iVBORw0KGgo=">', $document->body);
        self::assertStringContainsString('.x{background:url("data:image/png;base64,iVBORw0KGgo=")}', $document->html);
        $this->expectException(RuntimeException::class);
        $renderer->render(self::CERTIFICATE, 'Certificate', self::set(self::CERTIFICATE), '<img alt="" src="asset:' . str_repeat('b', 64) . '">', '', new PageSettings('A4', 'landscape', 0, 0, 0, 0), self::certificateData());
    }

    public function testADocumentAndAPageBecomePdfs(): void
    {
        $pdfs = new PdfRenderer(sys_get_temp_dir() . '/cattophp-dompdf-test');
        $document = $this->render(self::CERTIFICATE, '<div id="box" style="position:absolute;top:20mm;left:20mm;width:80mm">{{ learner.name }}</div>', '', self::certificateData());
        $laid = $pdfs->renderWithLayout($document, ['box']);
        self::assertStringStartsWith('%PDF-', $laid['pdf']);
        self::assertArrayHasKey('box', $laid['boxes'], 'Where a chosen element was laid out is reported.');
        self::assertEqualsWithDelta(80 / 25.4 * 72, $laid['boxes']['box']['w'], 1.0, 'In points.');
        self::assertStringStartsWith('%PDF-', $pdfs->render($document));
        self::assertStringStartsWith('%PDF-', $pdfs->renderPage('<!DOCTYPE html><html><head><style>@page{margin:20mm}</style></head><body><h1>Terms</h1></body></html>'));
        $fonts = sys_get_temp_dir() . '/cattophp-fonts-' . bin2hex(random_bytes(4));
        self::assertStringStartsWith('%PDF-', (new PdfRenderer(sys_get_temp_dir() . '/cattophp-dompdf-test', $fonts, []))->render($document), 'A folder without families leaves Dompdf\'s own fonts.');
    }

    /** @param array<string,mixed> $data */
    private function render(string $type, string $html, string $css, array $data): \CattoPHP\Document\RenderedDocument
    {
        return $this->renderer->render($type, ucfirst($type), self::set($type), $html, $css, new PageSettings('A4', 'portrait', 15, 15, 15, 15), $data);
    }

    /** @return list<string> */
    private function errors(string $type, string $html, string $css = ''): array
    {
        try {
            $this->validator->validate(self::set($type), ucfirst($type), $html, $css);

            return [];
        } catch (TemplateValidationException $invalid) {
            return $invalid->errors;
        }
    }

    /** An example project's placeholders: an invoice with lines, and a certificate without repeating data. */
    private static function set(string $type): PlaceholderSet
    {
        $business = [
            new PlaceholderDefinition('business.name', 'The seller.', PlaceholderKind::Text, true),
            new PlaceholderDefinition('business.website', 'Its website.', PlaceholderKind::Url),
        ];
        if ($type === self::CERTIFICATE) {
            return new PlaceholderSet([...$business,
                new PlaceholderDefinition('learner.name', 'Who earned it.', PlaceholderKind::Text, true),
                new PlaceholderDefinition('course.completion_date', 'When.', PlaceholderKind::Date, true),
            ]);
        }

        return new PlaceholderSet([...$business,
            new PlaceholderDefinition('invoice.number', 'Its number.', PlaceholderKind::Text, true),
            new PlaceholderDefinition('invoice.discount', 'A discount.', PlaceholderKind::Money),
            new PlaceholderDefinition('invoice.total', 'What it costs.', PlaceholderKind::Money, true),
            new PlaceholderDefinition('invoice.currency', 'Its currency.', PlaceholderKind::Text, true),
            new PlaceholderDefinition('customer.billing_name', 'Who pays.', PlaceholderKind::Text, true),
            new PlaceholderDefinition('customer.tax_number', 'Their tax number.'),
        ], [new CollectionDefinition('items', 'item', 'The lines.', [
            new PlaceholderDefinition('item.description', 'What was bought.', PlaceholderKind::Text, true),
            new PlaceholderDefinition('item.paid', 'What it cost.', PlaceholderKind::Money, true),
            new PlaceholderDefinition('item.discount', 'Its share of a discount.', PlaceholderKind::Money),
        ])]);
    }

    /** @return array<string,mixed> */
    private static function invoiceData(): array
    {
        $rand = static fn (int $minor): array => ['minor' => $minor, 'currency' => 'ZAR'];

        return ['business.name' => 'Example Gardens', 'business.website' => 'https://example.com', 'invoice.number' => 'INV-00000001', 'invoice.discount' => $rand(10000),
            'invoice.total' => $rand(290000), 'invoice.currency' => 'ZAR', 'customer.billing_name' => 'Ada Gardener', 'customer.tax_number' => '4000000001',
            'items' => [['description' => 'Pruning roses', 'paid' => $rand(120000), 'discount' => $rand(10000)], ['description' => 'Soil', 'paid' => $rand(90000)], ['description' => 'Compost', 'paid' => $rand(90000)]]];
    }

    /** @return array<string,mixed> */
    private static function certificateData(): array
    {
        return ['business.name' => 'Example Gardens', 'learner.name' => 'Ada Gardener', 'course.completion_date' => '2026-11-12'];
    }
}
