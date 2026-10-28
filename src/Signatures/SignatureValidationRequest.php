<?php

declare(strict_types=1);

namespace PDF\Html\Signatures;

use PDF\Reading\ReadDocument;
use PDF\Syntax\Object\DictionaryType;

/** Exact source graph and field; validation happens on the PDF, never the derived HTML. */
final readonly class SignatureValidationRequest
{
    public function __construct(public ReadDocument $document, public DictionaryType $signature, public string $fieldName)
    {
        if ($fieldName === '') {
            throw new \InvalidArgumentException('A signature validation request identifies its field.');
        }
    }
}
