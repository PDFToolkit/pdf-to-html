<?php

declare(strict_types=1);

namespace PDF\Html;

use PDF\Html\Reading\ReadPdfSource;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\EncryptedPdfException;

/** Coordinates explicit parsing, source interpretation and semantic derivation. */
final readonly class PdfToHtml
{
    private TaggedPdfSource $source;

    public function __construct(
        private DocumentReader $reader = new DocumentReader(),
        ?TaggedPdfSource $source = null,
        private HtmlDeriver $deriver = new HtmlDeriver(),
        private ?AuthenticationProvider $authentication = null,
    ) {
        $this->source = $source ?? new ReadPdfSource(policy: $deriver->policy, derivationLimits: $deriver->limits);
        if ($this->source instanceof ReadPdfSource && ($this->source->policy != $deriver->policy || $this->source->derivationLimits != $deriver->limits)) {
            throw new \InvalidArgumentException('Native source interpretation and HTML derivation must share the same policy and derivation limits.');
        }
    }

    public function convert(FileBytes $file, string $filename): DerivationResult
    {
        try {
            $parsed = $this->reader->read($file);
        } catch (EncryptedPdfException $error) {
            // Only the reader's verified /Encrypt condition enters this boundary.
            if ($this->authentication === null) {
                throw $error;
            }
            $document = $this->authentication->authenticate($file, $filename);
            if ($document->permissions === null || !$document->permissions->authenticated) {
                throw new DerivationException('Authentication must supply verified permissions for an encrypted source.');
            }
            return $this->deriver->derive($document);
        }
        $document = $this->source->read($parsed, $filename);
        $permissions = $parsed->permissions();
        if ($permissions !== null && ($document->permissions === null || !$document->permissions->authenticated || $document->permissions->mask !== $permissions->effectiveMask())) {
            throw new DerivationException('Source adaptation must preserve the native reader’s verified effective permissions.');
        }
        return $this->deriver->derive($document);
    }
}
