<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel;

use CattoPHP\Document\DocumentRenderer;
use CattoPHP\Document\PageSettings;
use CattoPHP\Document\PdfRenderer;
use CattoPHP\Document\PlaceholderDefinition;
use CattoPHP\Document\PlaceholderSet;
use CattoPHP\Tests\Kernel\App\TestKernel;
use CattoPHP\Tests\Support\Foundations;
use PHPUnit\Framework\TestCase;

/** A project switching documents on: the renderer and the PDF renderer as services, without a database. */
final class DocumentsKernelTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        $this->folder = Foundations::folder();
    }

    protected function tearDown(): void
    {
        Foundations::remove($this->folder);
        restore_exception_handler();
    }

    public function testADocumentIsRenderedAndDrawnAsAPdf(): void
    {
        $kernel = new TestKernel($this->folder, documents: true);
        $kernel->boot();
        try {
            $renderer = $kernel->getContainer()->get(DocumentRenderer::class);
            self::assertInstanceOf(DocumentRenderer::class, $renderer);
            $document = $renderer->render('note', 'Note', new PlaceholderSet([new PlaceholderDefinition('note.title', 'Its title.', required: true)]), '<h1>{{ note.title }}</h1>', '', new PageSettings('A4', 'portrait', 20, 20, 20, 20), ['note.title' => 'Hello']);
            self::assertSame('<h1>Hello</h1>', $document->body);
            $pdfs = $kernel->getContainer()->get(PdfRenderer::class);
            self::assertInstanceOf(PdfRenderer::class, $pdfs);
            self::assertStringStartsWith('%PDF-', $pdfs->render($document));
            self::assertDirectoryExists($this->folder . '/dompdf-fonts', 'The font cache is where the project says.');
        } finally {
            $kernel->shutdown();
        }
    }
}
