<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Http;

use CattoPHP\Http\HtmlLayout;
use PHPUnit\Framework\TestCase;

final class HtmlLayoutTest extends TestCase
{
    private const PAGE = <<<'HTML'
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>A  page</title>
<style>
  .a { color: red; }
</style><script type="application/ld+json">{"@type":"Organization","name":"Example \u003C/script\u003E Ltd","url":"https://example.org/"}</script>
<script nonce="n">document.documentElement.dataset.x = '1';</script></head>
<body class="b"><header><nav>
<a href="/">Home</a> <a href="/courses">Courses</a>
</nav>
</header>
<main id="main">
        <section>
<h1>Title</h1>
<p>Some <b>bold</b><i>glued</i> words
   over lines.</p>
<ul>
<li>One</li>
<li><a href="/x"><svg viewBox="0 0 1 1"><use href="#i"></use></svg>Two</a></li>
</ul>
<div class="menu"><details class="drop"><summary>Actions</summary></details><div class="buttons"><button>Go</button></div></div>
<div class="row">
<span class="a">A</span> <span class="b">B</span>
</div>
<div class="card">
<svg viewBox="0 0 2 2"><g><path d="M0 0"/><text>Hi  there</text></g></svg>
</div>
<div class="tight"><svg viewBox="0 0 3 3"><path d="M1 1"/></svg></div>
<div class="small muted">
   Access
   </div>
<td-like class="x"><b>a</b><div>block</div></td-like>
<pre>
  kept
    as is</pre>
<textarea name="t">  typed
</textarea>
<p></p>
<span> </span>
<div>   </div>
<a class="card-link"
   href="/c">
<span>Icon</span>
<span>Label</span>
</a>

</section>
</main>
<script src="/js/site.js"></script>
</body></html>
HTML;

    public function testEveryBlockIsOnLinesOfItsOwnOneTabALevel(): void
    {
        $page = (new HtmlLayout())->document(self::PAGE);
        $lines = explode("\n", rtrim($page));
        self::assertSame('<!doctype html>', $lines[0]);
        self::assertSame('<html lang="en">', $lines[1]);
        self::assertContains("\t<head>", $lines);
        self::assertContains("\t\t<meta charset=\"utf-8\">", $lines);
        self::assertContains("\t\t<title>A  page</title>", $lines, 'the title as it came');
        self::assertContains("\t<body class=\"b\">", $lines);
        self::assertContains("\t\t\t<section>", $lines);
        self::assertContains("\t\t\t\t<h1>Title</h1>", $lines);
        self::assertContains("\t\t\t\t<p>Some <b>bold</b><i>glued</i> words over lines.</p>", $lines, 'words on one line, glued elements kept together');
        self::assertContains("\t\t\t\t<div class=\"menu\"><details class=\"drop\"><summary>Actions</summary></details><div class=\"buttons\"><button>Go</button></div></div>", $lines, 'two blocks with nothing between stay together: CSS may draw them side by side');
        self::assertContains("\t\t\t\t\t<li>One</li>", $lines);
        self::assertContains("\t\t\t\t\t<li><a href=\"/x\"><svg viewBox=\"0 0 1 1\"><use href=\"#i\"></use></svg>Two</a></li>", $lines, 'an icon in a link stays on its line');
        self::assertSame('</html>', end($lines));
        foreach ($lines as $number => $line) {
            if (str_contains($line, 'kept') || str_contains($line, 'as is') || str_contains($line, 'typed') || str_contains($line, '.a {') || str_contains($line, '"')
                && !str_starts_with(ltrim($line, "\t"), '<')) {
                continue;
            }
            self::assertDoesNotMatchRegularExpression('/^\t* /', $line, 'line ' . $number . ' is indented with tabs only');
        }
    }

    public function testWhitespaceChangesOnlyWhereNothingDrawnChanges(): void
    {
        $page = (new HtmlLayout())->document(self::PAGE);
        self::assertStringContainsString("<style>\n  .a { color: red; }\n</style>", $page, 'a style as it came');
        self::assertStringContainsString("<pre>\n  kept\n    as is</pre>", $page, 'pre as it came');
        self::assertStringContainsString("<textarea name=\"t\">  typed\n</textarea>", $page, 'a text box as it came');
        self::assertStringContainsString("<script nonce=\"n\">document.documentElement.dataset.x = '1';</script>", $page, 'a script and its nonce as they came');
        self::assertStringContainsString('<p></p>', $page, 'an empty element stays empty, for :empty');
        self::assertStringContainsString('<span> </span>', $page, 'whitespace alone is one space');
        self::assertStringContainsString('<div> </div>', $page, 'in a block too, so :empty matches nothing new');
        self::assertStringContainsString("\t\t\t\t\t<span class=\"a\">A</span>\n\t\t\t\t\t<span class=\"b\">B</span>", $page, 'a row split only at the whitespace between them');
        self::assertSame(self::shape(self::PAGE), self::shape($page), 'a parser sees the same page, whitespace aside');
    }

    public function testALineBreakBesideWordsOnlyReplacesWhitespaceAlreadyThere(): void
    {
        $page = (new HtmlLayout())->document(self::PAGE);
        self::assertStringContainsString("\t\t\t\t<div class=\"small muted\">\n\t\t\t\t\tAccess\n\t\t\t\t</div>", $page, 'the whitespace at its edges kept, as line breaks: a trailing space counts in a cell\'s width');
        self::assertStringContainsString('<td-like class="x"><b>a</b><div>block</div></td-like>', $page, 'no whitespace between the words and the block: one line, as it was');
    }

    public function testAnInlineElementSpreadsOnlyWhereWhitespaceAlreadyIs(): void
    {
        $page = (new HtmlLayout())->document(self::PAGE);
        self::assertStringContainsString("\t\t\t\t<a class=\"card-link\" href=\"/c\">\n\t\t\t\t\t<span>Icon</span>\n\t\t\t\t\t<span>Label</span>\n\t\t\t\t</a>", $page, 'a start tag on one line, its children on lines of their own');
        $glued = (new HtmlLayout())->document("<!doctype html><html><head></head><body>\n<div><a href=\"/\"><span>A</span> <span>B</span></a></div>\n</body></html>");
        self::assertStringContainsString("\t\t<div><a href=\"/\"><span>A</span> <span>B</span></a></div>", $glued, 'no whitespace at its edges: one line, on its block\'s');
    }

    public function testADrawingIsLaidOutShapeByShapeButNotItsText(): void
    {
        $page = (new HtmlLayout())->document(self::PAGE);
        self::assertStringContainsString("\t\t\t\t<div class=\"tight\"><svg viewBox=\"0 0 3 3\"><path d=\"M1 1\"/></svg></div>", $page, 'no whitespace around it: one line, as it was');
        self::assertStringContainsString("<svg viewBox=\"0 0 2 2\">\n\t\t\t\t\t\t<g>\n\t\t\t\t\t\t\t<path d=\"M0 0\"/>\n\t\t\t\t\t\t\t<text>Hi  there</text>\n\t\t\t\t\t\t</g>\n\t\t\t\t\t</svg>", $page);
    }

    public function testStructuredDataIsLaidOutAndStaysSafeInItsScript(): void
    {
        $page = (new HtmlLayout())->document(self::PAGE);
        self::assertStringContainsString("<script type=\"application/ld+json\">\n\t\t\t{\n\t\t\t\t\"@type\": \"Organization\",", $page);
        self::assertStringContainsString('Example \\u003C/script\\u003E Ltd', $page, 'a closing script tag inside the data cannot end the script');
        self::assertStringContainsString("\n\t\t</script>", $page);
    }

    public function testAuthoredHtmlIsServedByteForByteAndTheMarksGo(): void
    {
        $layout = new HtmlLayout(['as-written']);
        $authored = "<p>  An  <em>author's</em>\n\t\tlines  </p>\n<pre> x</pre>";
        self::assertSame($authored, $layout->asWritten($authored), 'no mark while no page is being laid out');
        $layout->begin();
        $marked = $layout->asWritten($authored);
        $layout->end();
        self::assertNotSame($authored, $marked);
        $page = $layout->document('<!doctype html><html><head></head><body><main><div class="lesson">' . $marked . '</div><div class="x as-written">  <b>kept</b>' . "\n" . '  as  is </div></main></body></html>');
        self::assertStringContainsString($authored, $page, 'a marked region byte for byte');
        self::assertStringContainsString('<div class="x as-written">  <b>kept</b>' . "\n" . '  as  is </div>', $page, 'an element of a verbatim class byte for byte');
        self::assertStringNotContainsString('as-written:', $page, 'the marks go');
    }

    public function testMarkupThatDoesNotNestIsServedAsItCameWithoutTheMarks(): void
    {
        $layout = new HtmlLayout();
        $layout->begin();
        $marked = $layout->asWritten('<b>x</b>');
        $layout->end();
        $broken = "<!doctype html>\n<html><head></head><body><div><span>a</div></span>" . $marked . '</body></html>';
        self::assertSame(str_replace($marked, '<b>x</b>', $broken), $layout->document($broken));
        self::assertSame('<p>no page</p>', $layout->document('<p>no page</p>'), 'not a whole document');
    }

    public function testASecondLayoutChangesNothing(): void
    {
        $layout = new HtmlLayout();
        $once = $layout->document(self::PAGE);
        self::assertSame($once, $layout->document($once));
    }

    public function testALargePageIsLaidOutQuickly(): void
    {
        $row = '<tr><td class="cell"><a href="/item">An item</a></td><td><span class="badge">Open</span> <span>12</span></td></tr>';
        $page = "<!doctype html><html><head><title>Big</title></head><body>\n<main>\n<table><tbody>" . str_repeat($row, 8000) . "</tbody></table>\n</main>\n</body></html>";
        self::assertGreaterThan(900_000, strlen($page));
        $started = hrtime(true);
        $laid = (new HtmlLayout())->document($page);
        self::assertLessThan(1500, (hrtime(true) - $started) / 1e6, 'milliseconds for a page of nearly a megabyte');
        self::assertStringContainsString("\t\t\t\t\t<tr>\n\t\t\t\t\t\t<td class=\"cell\"><a href=\"/item\">An item</a></td>", $laid);
    }

    public function testAFlexOrGridContainersChildrenEachGoOnALineOfTheirOwn(): void
    {
        $layout = new HtmlLayout([], ['chips', 'chip']);
        $page = $layout->document("<!doctype html><html><head></head><body>\n<div class=\"chips\"><a class=\"chip\" href=\"/a\"><span class=\"icon\">*</span><span>Alpha</span></a><a class=\"chip x\" href=\"/b\"><span>Beta</span></a></div>\n<span class=\"chip\">Loose words</span>\n<div class=\"other\"><a href=\"/c\"><span>C</span><span>D</span></a></div>\n</body></html>");
        self::assertStringContainsString("\t\t<div class=\"chips\">\n\t\t\t<a class=\"chip\" href=\"/a\">\n\t\t\t\t<span class=\"icon\">*</span>\n\t\t\t\t<span>Alpha</span>\n\t\t\t</a>\n\t\t\t<a class=\"chip x\" href=\"/b\">\n\t\t\t\t<span>Beta</span>\n\t\t\t</a>\n\t\t</div>", $page);
        self::assertStringContainsString("\t\t<span class=\"chip\">Loose words</span>", $page, 'words of its own: as they were');
        self::assertStringContainsString("\t\t<div class=\"other\"><a href=\"/c\"><span>C</span><span>D</span></a></div>", $page, 'not one of the classes: as it was');
    }

    public function testAPageWithoutRoomInMemoryIsServedAsItCame(): void
    {
        $layout = new HtmlLayout();
        $page = "<!doctype html>\n<html><head></head><body>\n<main>\n" . str_repeat("<div>\n<p>A paragraph.</p>\n</div>\n", 20000) . "<b>authored</b>\n</main>\n</body></html>";
        $tight = new HtmlLayout([], [], memory_get_usage() + strlen($page) * 10);
        $tight->begin();
        $marked = $tight->asWritten('<b>authored</b>');
        $tight->end();
        $page = str_replace('<b>authored</b>', $marked, $page);
        self::assertSame(str_replace($marked, '<b>authored</b>', $page), $tight->document($page), 'not enough room to lay it out: as it came, without the marks');
        self::assertNotSame($page, $layout->document($page), 'with room, laid out');
    }

    /** The page as a parser sees it: elements, their attributes and their text, each run of whitespace one space. */
    private static function shape(string $html): string
    {
        $document = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $out = [];
        $walk = static function (\Dom\Node $node) use (&$walk, &$out): void {
            foreach ($node->childNodes as $child) {
                if ($child instanceof \Dom\Element) {
                    $attributes = [];
                    foreach ($child->attributes as $attribute) {
                        $attributes[] = $attribute->name . '=' . $attribute->value;
                    }
                    $out[] = '<' . $child->localName . ' ' . implode(' ', $attributes) . '>';
                    if (in_array($child->localName, ['pre', 'textarea', 'style'], true)) {
                        $out[] = 'as-is:' . $child->textContent;
                    } elseif ($child->localName === 'script') {
                        $out[] = 'script:' . json_encode(json_decode((string) $child->textContent, true) ?? $child->textContent);
                    } else {
                        $walk($child);
                    }
                    $out[] = '</' . $child->localName . '>';
                } elseif ($child instanceof \Dom\Text) {
                    $text = trim((string) preg_replace('/[ \t\n\r\f]+/', ' ', $child->data));
                    if ($text !== '') {
                        $out[] = 'text:' . $text;
                    }
                }
            }
        };
        $walk($document);

        return implode("\n", $out);
    }
}
