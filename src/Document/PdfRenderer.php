<?php

declare(strict_types=1);

namespace CattoPHP\Document;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Turns a rendered document, or a page of the site set out for print, into a PDF with Dompdf (dompdf/dompdf, which a
 * project requires). Remote resources, PHP and JavaScript are off: a document's pictures are embedded in it and its fonts
 * are the project's own, from the folder it names, each family in four faces (<File>-Regular.ttf, -Bold, -Italic and
 * -BoldItalic), registered once into a font cache.
 *
 * renderWithLayout() also reports where chosen elements were laid out, by id, so a caller can tell whether a fixed-size
 * box overflowed: an absolutely positioned box that is too full spills past the sheet on the same page rather than
 * starting a second one, so the page count alone cannot show it.
 */
final class PdfRenderer
{
    private const FACES = ['Regular' => ['normal', 'normal'], 'Bold' => ['bold', 'normal'], 'Italic' => ['normal', 'italic'], 'BoldItalic' => ['bold', 'italic']];

    private readonly string $fontCache;

    /**
     * @param string $fontCache where Dompdf keeps its font metrics: a writable folder of the installation
     * @param string $fonts the folder of the project's fonts; none, Dompdf's own only
     * @param array<string,string> $families each family's name in CSS => its files' prefix in that folder
     */
    public function __construct(string $fontCache = '', private readonly string $fonts = '', private readonly array $families = [])
    {
        $this->fontCache = $fontCache !== '' ? rtrim($fontCache, '/') : sys_get_temp_dir() . '/catto-dompdf-fonts';
    }

    public function render(RenderedDocument $document): string
    {
        return $this->renderWithLayout($document, [])['pdf'];
    }

    /** A page of the site set out for print as a whole HTML document, its margins in its own @page rule. */
    public function renderPage(string $html, string $size = 'A4', string $orientation = 'portrait'): string
    {
        $pdf = $this->dompdf();
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper($size, $orientation);
        $pdf->render();

        return (string) $pdf->output();
    }

    /**
     * @param list<string> $ids elements whose laid-out boxes to report
     * @return array{pdf:string,boxes:array<string,array{x:float,y:float,w:float,h:float}>} boxes in points
     */
    public function renderWithLayout(RenderedDocument $document, array $ids): array
    {
        $pdf = $this->dompdf();
        $boxes = [];
        if ($ids !== []) {
            $pdf->setCallbacks([['event' => 'end_frame', 'f' => static function ($frame) use (&$boxes, $ids): void {
                $node = $frame->get_node();
                if ($node instanceof \DOMElement && in_array($node->getAttribute('id'), $ids, true)) {
                    $box = $frame->get_border_box();
                    $boxes[$node->getAttribute('id')] = ['x' => (float) $box['x'], 'y' => (float) $box['y'], 'w' => (float) $box['w'], 'h' => (float) $box['h']];
                }
            }]]);
        }
        $pdf->loadHtml($document->html, 'UTF-8');
        $pdf->setPaper($document->settings->size, $document->settings->orientation);
        $pdf->render();

        return ['pdf' => (string) $pdf->output(), 'boxes' => $boxes];
    }

    private function dompdf(): Dompdf
    {
        if (!is_dir($this->fontCache)) {
            @mkdir($this->fontCache, 0775, true);
        }
        $fonts = rtrim($this->fonts, '/');
        $pdf = new Dompdf(new Options([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'defaultMediaType' => 'print',
            'defaultFont' => 'DejaVu Sans',
            'fontDir' => $this->fontCache,
            'fontCache' => $this->fontCache,
            'chroot' => $fonts !== '' ? [$fonts, $this->fontCache] : [$this->fontCache],
        ]));
        if ($fonts === '' || $this->families === []) {
            return $pdf;
        }
        $metrics = $pdf->getFontMetrics();
        $installed = $metrics->getFontFamilies();
        foreach ($this->families as $family => $file) {
            // Registering writes the metrics once; after that the family is already known.
            if (isset($installed[strtolower($family)]) && count($installed[strtolower($family)]) >= count(self::FACES)) {
                continue;
            }
            foreach (self::FACES as $face => [$weight, $style]) {
                $metrics->registerFont(['family' => $family, 'weight' => $weight, 'style' => $style], $fonts . '/' . $file . '-' . $face . '.ttf');
            }
        }

        return $pdf;
    }
}
