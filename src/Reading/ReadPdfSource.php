<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Html\Model\TaggedDocument;
use PDF\Reading\ReadDocument;

/** Adapt external tagged PDF objects without rebuilding the writer's mutable graph. */
final readonly class ReadPdfSource implements \PDF\Html\TaggedPdfSource
{
    public function __construct(public ReadingLimits $limits = new ReadingLimits())
    {
    }
    public function read(ReadDocument $document, string $filename): TaggedDocument
    {
        if ($filename === '' || str_contains($filename, "\0")) {
            throw new \InvalidArgumentException('A source filename must be nonempty and contain no NUL.');
        }
        return (new SourceReadSession(new SourceValues($document, new ReadingBudget($this->limits))))->read($filename);
    }
}
