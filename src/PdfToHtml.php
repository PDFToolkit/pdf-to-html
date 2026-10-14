<?php

declare(strict_types=1);

namespace PDF\Html;

use PDF\Html\Reading\ReadPdfSource;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\UnsupportedPdfFeature;

/** Coordinates explicit parsing, source interpretation and semantic derivation. */
final readonly class PdfToHtml
{
    public function __construct(
        private DocumentReader $reader = new DocumentReader(),
        private TaggedPdfSource $source = new ReadPdfSource(),
        private HtmlDeriver $deriver = new HtmlDeriver(),
        private ?AuthenticationProvider $authentication = null,
    ) {
    }

    public function convert(FileBytes $file, string $filename): DerivationResult
    {
        try {
            $document = $this->source->read($this->reader->read($file), $filename);
        } catch (UnsupportedPdfFeature $error) {
            // Only an explicit encrypted-file error invokes authentication.
            if ($this->authentication === null || !str_contains(strtolower($error->getMessage()), 'encrypt')) {
                throw $error;
            }
            $document = $this->authentication->authenticate($file, $filename);
            if ($document->permissions === null || !$document->permissions->authenticated) {
                throw new DerivationException('Authentication must supply verified permissions for an encrypted source.');
            }
        }
        return $this->deriver->derive($document);
    }
}
