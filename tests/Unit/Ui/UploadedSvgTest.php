<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Ui;

use CattoPHP\Ui\UploadedSvg;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UploadedSvgTest extends TestCase
{
    private const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 4h16v16H4z"/><circle cx="12" cy="12" r="3"/></svg>';

    public function testAPlainSvgPictureIsKeptAsItsSvgElement(): void
    {
        self::assertSame(self::ICON, UploadedSvg::validate("\xEF\xBB\xBF<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" . self::ICON . "\n", UploadedSvg::ICON_BYTES, 'icon'));
        $gradient = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 360"><defs><linearGradient id="bk-sky"><stop offset="0" stop-color="#0f3d3e"/></linearGradient></defs><rect width="640" height="360" fill="url(#bk-sky)"/></svg>';
        $kept = UploadedSvg::validate($gradient, UploadedSvg::PICTURE_BYTES, 'cover picture');
        self::assertMatchesRegularExpression('#^<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 360" class="(svg-[0-9a-f]{10})"><defs><linearGradient id="\\1-bk-sky"><stop offset="0" stop-color="\#0f3d3e"/></linearGradient></defs><rect width="640" height="360" fill="url\(\#\\1-bk-sky\)"/></svg>$#', $kept, 'A reference inside the picture is allowed, and its id is the picture\'s own.');
        self::assertSame($kept, UploadedSvg::validate($kept, UploadedSvg::PICTURE_BYTES, 'cover picture'), 'A picture made fit once is kept as it is.');
        self::assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', UploadedSvg::validate('<svg viewBox="0 0 24 24"><path d="M1 1h2"/></svg>', UploadedSvg::ICON_BYTES, 'icon'), 'A picture without its namespace is given it.');
    }

    /**
     * An icon from an icon site (such as one refused for its document type): the plain document type line
     * and comments are dropped, a one-colour icon takes the theme's colour and loses its fixed size, and a style
     * inside it is kept to it.
     */
    public function testAnIconFromAnIconSiteIsMadeFitForThePage(): void
    {
        $glyph = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<!DOCTYPE svg PUBLIC \"-//W3C//DTD SVG 1.0//EN\" \"http://www.w3.org/TR/2001/REC-SVG-20010904/DTD/svg10.dtd\">\n"
            . "<!-- Uploaded to: an icon site -->\n<svg version=\"1.0\" id=\"Layer_1\" xmlns=\"http://www.w3.org/2000/svg\" width=\"800px\" height=\"800px\" viewBox=\"0 0 64 64\"><!-- a lock --><path fill=\"#231F20\" d=\"M52 24h-4v-8z\"/></svg>";
        $icon = UploadedSvg::validate($glyph, UploadedSvg::ICON_BYTES, 'icon');
        self::assertSame('<svg xmlns="http://www.w3.org/2000/svg" version="1.0" viewBox="0 0 64 64"><path fill="currentColor" d="M52 24h-4v-8z"/></svg>', $icon);
        self::assertSame('<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 10 10"><path d="M1 1h8v8H1z"/></svg>',
            UploadedSvg::validate('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><path d="M1 1h8v8H1z"/></svg>', UploadedSvg::ICON_BYTES, 'icon'), 'No colour would draw it black: it takes the theme\'s.');
        $styled = UploadedSvg::validate('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><style>.st0{fill:none;stroke:#126BB5}.st1{fill:#00B1E4}path,svg .st0{opacity:.9}</style><path class="st0" d="M1 1h9"/><circle class="st1" cx="5" cy="5" r="2"/></svg>', UploadedSvg::ICON_BYTES, 'icon');
        self::assertSame(1, preg_match('/class="(svg-[0-9a-f]{10})"/', $styled, $found));
        $scope = $found[1] ?? '';
        self::assertStringContainsString('<style>.' . $scope . ' .st0{fill:none;stroke:#126BB5}.' . $scope . ' .st1{fill:#00B1E4}.' . $scope . ' path,.' . $scope . ' .st0{opacity:.9}</style>', $styled, 'Every rule reaches this picture only; two colours are kept.');
        self::assertSame($styled, UploadedSvg::validate($styled, UploadedSvg::ICON_BYTES, 'icon'));
        $cover = UploadedSvg::validate('<svg xmlns="http://www.w3.org/2000/svg" width="640" height="360" viewBox="0 0 640 360"><rect width="640" height="360" fill="#0f3d3e"/></svg>', UploadedSvg::PICTURE_BYTES, 'cover picture');
        self::assertStringContainsString('fill="#0f3d3e"', $cover, 'A cover keeps its colours and size.');
        self::assertStringContainsString('width="640"', $cover);
    }

    /** @return iterable<string,array{string,string}> */
    public static function refused(): iterable
    {
        yield 'not an svg' => ['<html><body>hi</body></html>', 'must be an SVG file'];
        yield 'not xml' => ['<svg><path></svg', 'must be an SVG file'];
        yield 'a script' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'it contains a <script> element'];
        yield 'an event attribute' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><path d="M1 1"/></svg>', 'event attribute'];
        yield 'an embedded document' => ['<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><p>x</p></foreignObject></svg>', 'foreignObject'];
        yield 'an outside link' => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><image xlink:href="https://example.test/a.png"/></svg>', 'must not link to anything outside itself'];
        yield 'an outside url in a fill' => ['<svg xmlns="http://www.w3.org/2000/svg"><rect fill="url(https://example.test/x)"/></svg>', 'must not link to anything outside itself'];
        yield 'a javascript link' => ['<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><path d="M1 1"/></a></svg>', 'must not link to anything outside itself'];
        yield 'a style import' => ['<svg xmlns="http://www.w3.org/2000/svg"><style>@import url(https://example.test/x.css);</style></svg>', 'must not link to anything outside itself'];
        yield 'a document type' => ['<!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>', 'must not declare a document type'];
        yield 'a public document type with entities' => ['<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd" [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>', 'must not declare a document type'];
        yield 'too large' => ['<svg xmlns="http://www.w3.org/2000/svg"><path d="' . str_repeat('M1 1 ', 5000) . '"/></svg>', 'at most 20 KB'];
    }

    #[DataProvider('refused')]
    public function testAnythingButAPictureIsRefusedWithTheReason(string $svg, string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);
        UploadedSvg::validate($svg, UploadedSvg::ICON_BYTES, 'icon');
    }

    public function testAFormWithoutAChosenFileKeepsTheCurrentPicture(): void
    {
        self::assertNull(UploadedSvg::fromUpload(null, UploadedSvg::ICON_BYTES, 'icon'));
        self::assertNull(UploadedSvg::fromUpload(['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '', 'size' => 0], UploadedSvg::ICON_BYTES, 'icon'));
        $this->expectExceptionMessage('The icon could not be uploaded. Choose the SVG file again.');
        UploadedSvg::fromUpload(['error' => UPLOAD_ERR_PARTIAL, 'tmp_name' => '', 'size' => 10], UploadedSvg::ICON_BYTES, 'icon');
    }
}
