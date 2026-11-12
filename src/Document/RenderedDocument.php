<?php

declare(strict_types=1);

namespace CattoPHP\Document;

/**
 * A rendered document: the HTML a PDF or the preview is drawn from, and exactly which template and
 * version produced it, so the caller can record them with the document it issues and render the same
 * version again later.
 */
final class RenderedDocument
{
    public function __construct(
        /** The project's name for the kind of document, such as invoice. */
        public readonly string $type,
        /** The complete HTML document: head, page box, template CSS and body. */
        public readonly string $html,
        /** The rendered template body alone. */
        public readonly string $body,
        public readonly PageSettings $settings,
        /** Null only when unsaved draft content was rendered, as a preview of edits does. */
        public readonly ?int $templateId = null,
        public readonly ?int $versionId = null,
        public readonly ?int $versionNumber = null,
    ) {}

    /**
     * What a document records about its template: enough to render this exact version again.
     *
     * @return array{document_type:string,template_id:?int,template_version_id:?int,template_version_number:?int}
     */
    public function reference(): array
    {
        return [
            'document_type' => $this->type,
            'template_id' => $this->templateId,
            'template_version_id' => $this->versionId,
            'template_version_number' => $this->versionNumber,
        ];
    }
}
