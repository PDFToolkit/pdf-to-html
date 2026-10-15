<?php

declare(strict_types=1);

namespace PDF\Html;

use PDF\Html\Reading\ReadPdfSource;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\EncryptedPdfException;
use PDF\Reading\Security\SourceIntegrityPolicy;

/** Coordinates explicit parsing, source interpretation and semantic derivation. */
final readonly class PdfToHtml
{
    private TaggedPdfSource $source;
    private DocumentReader $reader;

    public function __construct(
        ?DocumentReader $reader = null,
        ?TaggedPdfSource $source = null,
        private HtmlDeriver $deriver = new HtmlDeriver(),
        private ?AuthenticationProvider $authentication = null,
        SourceIntegrityPolicy $sourceIntegrity = SourceIntegrityPolicy::AcceptUnprotected,
    ) {
        $this->reader = ($reader ?? new DocumentReader())->requiringSourceIntegrity($sourceIntegrity);
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
            $parsed = $this->authentication->authenticate($file, $filename, $this->reader->sourceIntegrityPolicy());
            if ($parsed->permissions() === null) {
                throw new DerivationException('Authentication must supply verified permissions for an encrypted source.');
            }
            $origin = $parsed->sourceRevision()->source;
            if ($origin !== $file && !hash_equals($file->bytes, $origin->bytes)) {
                throw new DerivationException('Authentication must return the graph of the exact supplied source bytes.');
            }
        }
        if ($this->reader->sourceIntegrityPolicy() === SourceIntegrityPolicy::RequirePdfMac && $parsed->mac() === null) {
            throw new DerivationException('Source interpretation requires a verified PDF MAC under the selected integrity policy.');
        }
        $document = $this->source->read($parsed, $filename);
        $permissions = $parsed->permissions();
        if ($permissions !== null && ($document->permissions === null || !$document->permissions->authenticated || $document->permissions->mask !== $permissions->effectiveMask())) {
            throw new DerivationException('Source adaptation must preserve the native reader’s verified effective permissions.');
        }
        return $this->deriver->derive($document);
    }
}
