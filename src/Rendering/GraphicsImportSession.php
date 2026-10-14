<?php

declare(strict_types=1);

namespace PDF\Html\Rendering;

use PDF\Html\DerivationException;
use PDF\Reading\EncodedStream;
use PDF\Reading\ObjectReference;
use PDF\Reading\ReadDocument;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\StringType;
use PDF\Syntax\Object\IndirectReference;

/** Mutable identity and cumulative budgets belong to one isolated graphics export. */
final class GraphicsImportSession
{
    /** @var array<string, BasicType> */
    private array $objects = [];
    private int $nodes = 0;
    private int $bytes = 0;
    /** @var array<string, true> */
    private array $loading = [];

    /** @param array<int, string> $streamOverrides */
    public function __construct(private readonly ReadDocument $source, private readonly GraphicsExportLimits $limits, private readonly array $streamOverrides = [])
    {
    }

    public function object(ObjectReference $reference): BasicType
    {
        $key = (string) $reference->identifier;
        if (isset($this->objects[$key])) {
            return $this->objects[$key];
        }
        if (isset($this->loading[$key])) {
            throw new DerivationException('A non-dictionary graphics resource has a cyclic reference.');
        }
        $this->loading[$key] = true;
        try {
            return $this->objects[$key] = $this->import($this->source->resolve($reference));
        } finally {
            unset($this->loading[$key]);
        }
    }

    public function import(BasicType $value, int $depth = 0): BasicType
    {
        if (++$this->nodes > $this->limits->maximumObjects || $depth > $this->limits->maximumDepth) {
            throw new DerivationException('The graphics resource graph exceeds its export limits.');
        }
        if ($value instanceof ObjectReference) {
            return new IndirectReference($this->object($value));
        }
        if ($value instanceof EncodedStream) {
            if ($value->dictionary->has('F')) {
                throw new DerivationException('External stream files cannot enter a rendering document.');
            }
            $bytes = $this->streamOverrides[spl_object_id($value)] ?? $value->bytes;
            $this->retain(strlen($bytes));
            return new ImportedStream($value->dictionary, $bytes, $this, isset($this->streamOverrides[spl_object_id($value)]));
        }
        if ($value instanceof DictionaryType) {
            return new ImportedDictionary($value, $this);
        }
        if ($value instanceof ArrayType) {
            return new ArrayType(...array_map(fn (BasicType $child): BasicType => $this->import($child, $depth + 1), $value->items));
        }
        if ($value instanceof StringType) {
            $this->retain(strlen($value->value));
        }
        return $value;
    }

    public function dictionary(DictionaryType $dictionary, int $depth = 0, bool $stream = false, bool $decodedOverride = false): DictionaryType
    {
        $entries = [];
        foreach ($dictionary->entries() as $entry) {
            if ($stream && $entry->key->value === 'Length') {
                continue;
            }
            if ($decodedOverride && in_array($entry->key->value, ['Filter', 'DecodeParms'], true)) {
                continue;
            }
            // These entries do not contribute to painting and can refer to active/remote content.
            if (in_array($entry->key->value, ['AA', 'OpenAction', 'JS', 'OPI', 'Metadata'], true)) {
                continue;
            }
            $this->retain(strlen($entry->key->value));
            $entries[] = new Entry($entry->key, $this->import($entry->value, $depth));
        }
        return new DictionaryType(...$entries);
    }

    public function retain(int $length): void
    {
        if ($length < 0 || $length > $this->limits->maximumBytes - $this->bytes) {
            throw new DerivationException('The cumulative graphics export byte limit was exceeded.');
        }
        $this->bytes += $length;
    }
}
