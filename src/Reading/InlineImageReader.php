<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Reading\ObjectReference;
use PDF\Reading\ByteCursor;
use PDF\Reading\ParseException;
use PDF\Reading\ReadDocument;
use PDF\Reading\SyntaxParser;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;

/** ISO 32000 inline image syntax; explicit resources, codec endpoints and cumulative budgets. */
final readonly class InlineImageReader
{
    public function __construct(private ReadingBudget $budget, private ?ReadDocument $document = null, private ?InlineImageBoundaryProvider $boundary = null)
    {
    }

    public function read(SyntaxParser $parser, ?DictionaryType $resources = null): InlineImageData
    {
        try {
            $entries = [];
            while (!$this->atData($parser->cursor)) {
                $this->budget->consume('instructions', 2);
                $key = $parser->read();
                if (!$key instanceof NameType) {
                    throw new ReadingException('An inline image parameter requires a name key.');
                }
                $key = $key->value;
                if (isset($entries[$key])) {
                    throw new ReadingException('An inline image has duplicate parameter keys.');
                }
                $value = $parser->read();
                $this->direct($value, 1);
                $entries[$key] = $value;
            }
            // EC3 issue3: abbreviations win independently of source order.
            foreach (['W' => 'Width', 'H' => 'Height', 'BPC' => 'BitsPerComponent',
                'CS' => 'ColorSpace', 'D' => 'Decode', 'DP' => 'DecodeParms',
                'F' => 'Filter', 'IM' => 'ImageMask', 'I' => 'Interpolate', 'L' => 'Length'] as $short => $full) {
                if (isset($entries[$short])) {
                    $entries[$full] = $entries[$short];
                    unset($entries[$short]);
                }
            }
            $parameters = [];
            foreach ($entries as $key => $value) {
                if ($key === 'Filter') {
                    $value = $this->filter($value);
                } elseif ($key === 'ColorSpace') {
                    // Preserve named resources without introducing BI references.
                    $value = $this->color($value, null);
                }
                $parameters[] = new Entry(new NameType($key), $value);
            }
            $dictionary = new DictionaryType(...$parameters);
            $this->beginData($parser->cursor, $dictionary);
            $width = $this->positive($dictionary, 'Width');
            $height = $this->positive($dictionary, 'Height');
            $mask = $dictionary->get('ImageMask');
            if ($mask !== null && !$mask instanceof BooleanType) {
                throw new ReadingException('An inline image ImageMask parameter must be boolean.');
            }
            $mask = $mask instanceof BooleanType && $mask->value;
            if (!$mask && $dictionary->get('ColorSpace') === null) {
                throw new ReadingException('An inline image requires its colour space unless it is an image mask.');
            }
            $bits = $dictionary->get('BitsPerComponent');
            if ($bits === null && $mask) {
                $bits = new IntegerType(1);
                $dictionary = $dictionary->with(new Entry(new NameType('BitsPerComponent'), $bits));
            }
            if (!$bits instanceof IntegerType || !in_array($bits->integer(), [1, 2, 4, 8, 16], true) || $mask && $bits->integer() !== 1) {
                throw new ReadingException('An inline image requires a supported positive sample bit depth.');
            }
            $filter = $dictionary->get('Filter');
            $first = $filter instanceof ArrayType ? ($filter->items[0] ?? null) : $filter;
            $name = $first instanceof NameType ? $first->value : null;
            $color = $dictionary->get('ColorSpace');
            $color = $color === null ? null : $this->color($color, $resources);
            $components = $mask ? 1 : $this->components($color);
            $rawLength = $name === null && $components !== null ? $this->sampleLength($width, $height, $bits->integer(), $components) : null;
            $parameters = $dictionary->get('DecodeParms');
            $parameters = $parameters instanceof ArrayType ? ($parameters->items[0] ?? null) : $parameters;
            $early = $parameters instanceof DictionaryType ? $parameters->get('EarlyChange') : null;
            if ($early !== null && !$early instanceof IntegerType) {
                throw new ReadingException('Inline LZW EarlyChange must be an integer.');
            }
            $data = (new InlineImageBoundaryReader($this->budget, $this->boundary))->read($parser->cursor, $name, $rawLength, $early instanceof IntegerType ? $early->integer() : 1, $dictionary);
            if ($parser->cursor->eof() || !str_contains("\0\t\n\f\r ", $parser->cursor->peek())) {
                throw new ReadingException('Inline image data must be followed by whitespace and the EI operator.');
            }
            $parser->keyword('EI');
            return new InlineImageData($dictionary, $data);
        } catch (ParseException $exception) {
            throw new ReadingException('An inline image is truncated or has invalid syntax: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private function atData(ByteCursor $cursor): bool
    {
        $cursor->skipWhitespaceAndComments();
        // Between parameter pairs only a name or ID is legal. ASCII image data
        // is allowed to follow ID without the usual PDF keyword delimiter.
        return $cursor->peek(2) === 'ID';
    }

    private function beginData(ByteCursor $cursor, DictionaryType $dictionary): void
    {
        $cursor->read(2);
        $separator = $cursor->peek();
        if ($separator === '') {
            throw new ReadingException('An inline image has no data after ID.');
        }
        if (str_contains("\0\t\n\f\r ", $separator)) {
            $cursor->read(1);
            if ($separator === "\r" && $cursor->peek() === "\n") {
                $cursor->read(1);
            }
            return;
        }
        $filter = $dictionary->get('Filter');
        $last = $filter instanceof ArrayType ? ($filter->items === [] ? null : $filter->items[array_key_last($filter->items)]) : $filter;
        // ISO 32000-2 8.9.7, physical p. 283, EC3 issue 20: final or only filter.
        if (!$last instanceof NameType || !in_array($last->value, ['ASCIIHexDecode', 'ASCII85Decode'], true)) {
            throw new ReadingException('An inline image ID requires whitespace unless its final or only filter is ASCIIHexDecode or ASCII85Decode.');
        }
    }

    private function positive(DictionaryType $dictionary, string $key): int
    {
        $value = $dictionary->get($key);
        if (!$value instanceof IntegerType || $value->integer() < 1) {
            throw new ReadingException('An inline image requires positive integer ' . $key . '.');
        }
        return $value->integer();
    }

    private function direct(BasicType $value, int $depth): void
    {
        $this->budget->depth($depth);
        if ($value instanceof ObjectReference) {
            throw new ReadingException('Inline image parameters may not contain indirect object references.');
        }
        foreach ($value instanceof ArrayType ? $value->items : ($value instanceof DictionaryType ? array_map(static fn (Entry $entry): BasicType => $entry->value, iterator_to_array($value->entries(), false)) : []) as $child) {
            $this->direct($child, $depth + 1);
        }
    }

    private function filter(BasicType $value): BasicType
    {
        if ($value instanceof NullType) {
            return $value;
        }
        if ($value instanceof ArrayType) {
            $filters = [];
            foreach ($value->items as $filter) {
                if (!$filter instanceof NameType) {
                    throw new ReadingException('Inline image filter arrays must contain only names.');
                }
                $filters[] = $this->filter($filter);
            }
            return new ArrayType(...$filters);
        }
        if (!$value instanceof NameType) {
            throw new ReadingException('Inline image filters must be names.');
        }
        return new NameType(match ($value->value) {
            'AHx' => 'ASCIIHexDecode', 'A85' => 'ASCII85Decode', 'LZW' => 'LZWDecode',
            'Fl' => 'FlateDecode', 'RL' => 'RunLengthDecode', 'CCF' => 'CCITTFaxDecode',
            'DCT' => 'DCTDecode', default => $value->value,
        });
    }

    private function color(BasicType $value, ?DictionaryType $resources): BasicType
    {
        if ($value instanceof ArrayType) {
            $items = $value->items;
            if (isset($items[0]) && $items[0] instanceof NameType && $items[0]->value === 'I') {
                $items[0] = new NameType('Indexed');
            }
            return new ArrayType(...$items);
        }
        if (!$value instanceof NameType) {
            return $value;
        }
        $name = match ($value->value) {
            'G' => 'DeviceGray', 'RGB' => 'DeviceRGB', 'CMYK' => 'DeviceCMYK', 'I' => 'Indexed', default => $value->value,
        };
        if (in_array($name, ['DeviceGray', 'DeviceRGB', 'DeviceCMYK'], true)) {
            return new NameType($name);
        }
        $category = $resources?->get('ColorSpace');
        $category = $category !== null ? $this->document?->resolve($category) ?? $category : null;
        $space = $category instanceof DictionaryType ? $category->get($name) : null;
        return $space === null ? $value : $this->document?->resolve($space) ?? $space;
    }

    private function components(?BasicType $color): ?int
    {
        if ($color instanceof NameType) {
            return match ($color->value) {
                'DeviceGray' => 1, 'DeviceRGB' => 3, 'DeviceCMYK' => 4, default => null,
            };
        }
        if (!$color instanceof ArrayType || !isset($color->items[0]) || !$color->items[0] instanceof NameType) {
            return null;
        }
        return match ($color->items[0]->value) {
            'CalGray', 'Indexed', 'Separation' => 1,
            'CalRGB', 'Lab' => 3,
            'DeviceN' => isset($color->items[1]) && $color->items[1] instanceof ArrayType ? count($color->items[1]) : null,
            'ICCBased' => $this->iccComponents($color->items[1] ?? null),
            default => null,
        };
    }

    private function iccComponents(?BasicType $profile): ?int
    {
        $profile = $profile === null ? null : $this->document?->resolve($profile) ?? $profile;
        $count = $profile instanceof \PDF\Reading\EncodedStream ? $profile->dictionary()->get('N') : null;
        return $count instanceof IntegerType && in_array($count->integer(), [1, 3, 4], true) ? $count->integer() : null;
    }

    private function sampleLength(int $width, int $height, int $bits, int $components): int
    {
        if ($components < 1 || $width > intdiv(PHP_INT_MAX - 7, $bits * $components)) {
            throw new ReadingException('Inline image sample dimensions exceed the integer range.');
        }
        $row = intdiv($width * $bits * $components + 7, 8);
        if ($row > intdiv($this->budget->limits->maximumContentBytes, $height)) {
            throw new ReadingException('Inline image samples exceed the source content limit.');
        }
        return $row * $height;
    }
}
