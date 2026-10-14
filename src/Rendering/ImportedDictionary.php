<?php

declare(strict_types=1);

namespace PDF\Html\Rendering;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;

/** Lazy, document-local identity permits legal indirect resource cycles without recursive copying. */
final class ImportedDictionary extends DictionaryType
{
    private ?DictionaryType $imported = null;

    public function __construct(private readonly DictionaryType $original, private readonly GraphicsImportSession $session, private readonly int $depth)
    {
        parent::__construct();
    }

    /** @return iterable<Entry> */
    public function entries(): iterable
    {
        $this->imported ??= $this->session->dictionary($this->original, $this->depth);
        yield from $this->imported->entries();
    }
}
