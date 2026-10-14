<?php

declare(strict_types=1);

namespace PDF\Html;

use PDF\Html\Model\TaggedDocument;
use PDF\Reading\ReadDocument;

/** An explicit interpretation boundary, replaceable independently of derivation. */
interface TaggedPdfSource
{
    public function read(ReadDocument $document, string $filename): TaggedDocument;
}
