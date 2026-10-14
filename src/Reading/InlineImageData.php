<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Reading\EncodedStream;
use PDF\Syntax\Object\DictionaryType;

/** Exact inline bytes and normalized image parameters, without an indirect-object identity. */
final readonly class InlineImageData
{
    public function __construct(public DictionaryType $dictionary, public string $bytes)
    {
    }

    public function stream(): EncodedStream
    {
        return new EncodedStream($this->dictionary, $this->bytes);
    }
}
