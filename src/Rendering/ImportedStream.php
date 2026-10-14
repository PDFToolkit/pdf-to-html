<?php

declare(strict_types=1);

namespace PDF\Html\Rendering;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** Preserve compressed resources byte for byte; no unsupported codec is invoked by export. */
final class ImportedStream extends StreamObject
{
    private ?DictionaryType $imported = null;

    public function __construct(private readonly DictionaryType $original, private readonly string $encodedBytes, private readonly GraphicsImportSession $session, private readonly bool $override, private readonly int $depth)
    {
        parent::__construct('');
    }

    public function dictionary(): DictionaryType
    {
        return $this->imported ??= $this->session->dictionary($this->original, $this->depth, stream: true, decodedOverride: $this->override);
    }

    public function bytes(SerializationContext $context): string
    {
        return $this->encodedBytes;
    }

    public function toPdf(SerializationContext $context): string
    {
        $dictionary = $this->dictionary()->with(new Entry(new NameType('Length'), new IntegerType(strlen($this->encodedBytes))));
        $context->recordStreamEmission($this, $dictionary, $this->encodedBytes);
        return $context->serialize($dictionary)."\nstream\n".$this->encodedBytes."\nendstream";
    }
}
