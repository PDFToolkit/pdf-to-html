<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Html\Model\StructureProperties;
use PDF\Reading\ReadDocument;
use PDF\Syntax\DataStructure\TextStringDecoder;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringType;

/** Typed source access with explicit resolution, version and resource budget. */
final readonly class SourceValues
{
    private TextStringDecoder $textDecoder;
    public function __construct(public ReadDocument $document, public ReadingBudget $budget)
    {
        $version = $document->catalog()->get('Version');
        $this->textDecoder = new TextStringDecoder($version instanceof NameType && $version->value === '2.0' ? PdfVersion::PDF20 : $document->header()->version);
    }
    public function value(DictionaryType $dictionary, string $key): ?BasicType
    {
        $value = $dictionary->get($key);
        return $value === null ? null : $this->document->resolve($value);
    }
    public function dictionary(?BasicType $value): ?DictionaryType
    {
        $value = $value === null ? null : $this->document->resolve($value);
        return $value instanceof DictionaryType ? $value : null;
    }
    public function name(?BasicType $value): ?string
    {
        $value = $value === null ? null : $this->document->resolve($value);
        return $value instanceof NameType ? $value->value : null;
    }
    public function text(?BasicType $value): ?string
    {
        $value = $value === null ? null : $this->document->resolve($value);
        if (!$value instanceof StringType) {
            return null;
        }
        $text = $this->textDecoder->decode($value);
        $this->budget->consume('text', strlen($text));
        return $text;
    }
    public function integer(?BasicType $value, int $default = 0): int
    {
        $value = $value === null ? null : $this->document->resolve($value);
        return $value instanceof IntegerType ? $value->integer() : $default;
    }
    public function number(?BasicType $value, float $default = 0): float
    {
        $value = $value === null ? null : $this->document->resolve($value);
        return $value instanceof NumericType ? (float) $value->value : $default;
    }
    /** @return list<BasicType> */
    public function items(?BasicType $value): array
    {
        $value = $value === null ? null : $this->document->resolve($value);
        return $value instanceof ArrayType ? $value->items : ($value === null || $value instanceof NullType ? [] : [$value]);
    }
    public function native(?BasicType $value, int $depth = 1): mixed
    {
        $this->budget->depth($depth);
        $this->budget->consume('nodes');
        $value = $value === null ? null : $this->document->resolve($value);
        if ($value instanceof NameType) {
            return $value->value;
        }
        if ($value instanceof StringType) {
            return $this->text($value);
        }
        if ($value instanceof BooleanType) {
            return $value->value;
        }
        if ($value instanceof NumericType) {
            return $value->value;
        }
        if ($value instanceof ArrayType) {
            return array_map(fn (BasicType $item): mixed => $this->native($item, $depth + 1), $value->items);
        }
        if ($value instanceof DictionaryType) {
            $result = [];
            foreach ($value->entries() as $entry) {
                $result[$entry->key->value] = $this->native($entry->value, $depth + 1);
            }
            return $result;
        }
        return null;
    }
    /** @param list<string> $classes */
    public function properties(DictionaryType $dictionary, array $classes = []): StructureProperties
    {
        $references = [];
        foreach ($this->items($dictionary->get('Ref')) as $reference) {
            $references[] = $this->key($reference);
        }
        return new StructureProperties($this->identifier($dictionary->get('ID')), $this->optionalText($dictionary, 'Lang'), $this->text($dictionary->get('ActualText')), $this->optionalText($dictionary, 'Alt'), $this->optionalText($dictionary, 'E'), $classes, $references);
    }
    private function optionalText(DictionaryType $dictionary, string $key): ?string
    {
        try {
            return $this->text($dictionary->get($key));
        } catch (\InvalidArgumentException $exception) {
            $this->budget->diagnostic('The source /' . $key . ' property has invalid PDF text encoding and was omitted: ' . $exception->getMessage());
            return null;
        }
    }
    /** Preserve a PDF byte-string identity consistently for ID and Headers. */
    public function identifier(?BasicType $value): ?string
    {
        $value = $value === null ? null : $this->document->resolve($value);
        if (!$value instanceof StringType) {
            return null;
        }
        // Structure ID is a byte string, not a PDF text string; preserve arbitrary bytes injectively.
        $bytes = $value->value;
        $identifier = $bytes !== '' && preg_match('//u', $bytes) === 1 && preg_match('/[\s\x00-\x1f\x7f]/u', $bytes) !== 1 && !str_starts_with($bytes, 'pdf-id-') ? $bytes : 'pdf-id-' . bin2hex($bytes);
        $this->budget->consume('text', strlen($identifier));
        return $identifier;
    }
    public function key(BasicType $value): string
    {
        if ($value instanceof \PDF\Reading\ObjectReference) {
            return 'obj-' . $value->identifier->objectNumber->value . '-' . $value->identifier->generationNumber->value;
        }
        return 'direct-' . spl_object_id($value);
    }
}
