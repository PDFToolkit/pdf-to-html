<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Html\Model\ContentGroup;
use PDF\Html\Model\GraphicContent;
use PDF\Html\Model\GraphicProgram;
use PDF\Html\Model\ImageContent;
use PDF\Html\Model\MarkedContent;
use PDF\Html\Model\SourceNode;
use PDF\Html\Model\StructureProperties;
use PDF\Html\Model\TextContent;
use PDF\Reading\EncodedStream;
use PDF\Reading\PageContentsReader;
use PDF\Reading\ReadPage;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringType;

/** Source content extraction for 4.4 (physical PDF pages 52–56), with no rendering or I/O. */
final class ContentReader
{
    /** @var array<string, array<int, MarkedContent>> */
    private array $marked = [];
    /** @var array<string, list<SourceNode>> */
    private array $complete = [];
    /** @var array<int, true> */
    private array $loading = [];
    /** @var array<string, array{int, ?int, array{float, float, float, float}}> */
    private array $streamContexts = [];
    /** @var array<string, ImageContent|GraphicContent> */
    private array $paintedImages = [];
    /** @var array<string, true> */
    private array $ambiguousImages = [];
    /** @var array<string, array<int, true>> */
    private array $replacedContent = [];
    private FontTextDecoder $fonts;
    public function __construct(private readonly SourceValues $values)
    {
        $this->fonts = new FontTextDecoder($values);
    }

    /** Register replacements before interpreting any shared page or Form content. */
    public function replaceMarked(ReadPage $page, int $number, ?EncodedStream $stream = null): void
    {
        $key = $stream === null ? $this->pageKey($page) : $this->streamKey($page, $stream);
        if (isset($this->complete[$key])) {
            throw new \LogicException('Register replaced content before interpreting its container.');
        }
        $this->replacedContent[$key][$number] = true;
    }

    public function marked(ReadPage $page, int $number, ?EncodedStream $stream = null): MarkedContent
    {
        $key = $stream === null ? $this->pageKey($page) : $this->streamKey($page, $stream);
        if (!isset($this->complete[$key])) {
            if ($stream === null) {
                $this->page($page);
            } else {
                $this->page($page);
                if (!isset($this->complete[$key])) {
                    $this->stream($page, $stream, $page->attributes->resources, 1);
                }
            }
        }
        return $this->marked[$key][$number] ?? throw new ReadingException('Structure MCID ' . $number . ' is missing from content container ' . $key . '.');
    }
    /** @return list<SourceNode> */
    public function page(ReadPage $page): array
    {
        $key = $this->pageKey($page);
        if (isset($this->complete[$key])) {
            return $this->complete[$key];
        }
        $bytes = '';
        foreach ((new PageContentsReader($this->values->document))->read($page) as $stream) {
            $part = $this->values->document->streamData($stream);
            if (strlen($part) + strlen($bytes) + 1 > $this->values->budget->limits->maximumContentBytes) {
                throw new ReadingException('Combined page content exceeds the source content limit.');
            }
            $bytes .= $part . "\n";
        }
        return $this->complete[$key] = $this->instructions($bytes, $page->attributes->resources, $page, $key, 1, initialMatrix: $this->pageMatrix($page));
    }
    /** @return list<SourceNode> */
    public function stream(ReadPage $page, EncodedStream $stream, DictionaryType $resources, int $depth, ?DictionaryType $initialFont = null, ?ContentMatrix $initialMatrix = null, ?GraphicProgram $parent = null): array
    {
        $this->values->budget->depth($depth);
        $key = $this->streamKey($page, $stream);
        $identity = spl_object_id($stream);
        if ($initialMatrix === null && isset($this->complete[$key])) {
            return $this->complete[$key];
        }
        $matrix = $initialMatrix ?? $this->pageMatrix($page);
        $formMatrix = $this->values->value($stream->dictionary(), 'Matrix');
        if ($formMatrix !== null) {
            if (!$formMatrix instanceof ArrayType) {
                throw new ReadingException('A Form XObject Matrix must contain six numeric coefficients.');
            }
            $matrix = $matrix->concatenate(ContentMatrix::operands($formMatrix->items));
        }
        $resources = $this->values->dictionary($stream->dictionary()->get('Resources')) ?? $resources;
        $context = [spl_object_id($resources), $initialFont === null ? null : spl_object_id($initialFont), $matrix->linear()];
        if (isset($this->streamContexts[$key]) && $this->streamContexts[$key] !== $context) {
            throw new ReadingException('A repeated Form XObject has ambiguous inherited font, resource or transformation contexts.');
        }
        $this->streamContexts[$key] = $context;
        if (isset($this->loading[$identity])) {
            throw new ReadingException('Form XObject content contains a recursive invocation.');
        }
        if (isset($this->complete[$key])) {
            return $this->complete[$key];
        }
        $this->loading[$identity] = true;
        try {
            $box = $this->values->value($stream->dictionary(), 'BBox');
            if ($box !== null && !$box instanceof ArrayType) {
                throw new ReadingException('A Form XObject BBox requires four numeric coordinates.');
            }
            $bounds = $box instanceof ArrayType ? $this->transformedBox($box, $matrix) : $this->pageBox($page);
            return $this->complete[$key] = $this->instructions($this->values->document->streamData($stream), $resources, $page, $key, $depth, $initialFont, $matrix, $bounds, $parent, $stream);
        } finally {
            unset($this->loading[$identity]);
        }
    }
    public function image(EncodedStream $stream, int $page, ?ContentMatrix $matrix = null, ?GraphicProgram $source = null): ImageContent|GraphicContent
    {
        $key = $page . ':' . spl_object_id($stream);
        if ($matrix === null) {
            if (isset($this->ambiguousImages[$key])) {
                return new GraphicContent('unsupported-image', 'An object-reference image has several distinct painting transformations; its logical dimensions are ambiguous.', page: $page);
            }
            return $this->paintedImages[$key] ?? new GraphicContent('unsupported-image', 'An object-reference image is not painted on its associated page; its logical dimensions are unavailable.', page: $page);
        }
        [$logicalWidth, $logicalHeight] = $matrix->unitDimensions();
        $image = $this->portableImage($stream, $page, $logicalWidth, $logicalHeight, $source);
        if (isset($this->paintedImages[$key]) && ($this->paintedImages[$key]->widthPoints !== $logicalWidth || $this->paintedImages[$key]->heightPoints !== $logicalHeight)) {
            $this->ambiguousImages[$key] = true;
        }
        $this->paintedImages[$key] ??= $image;
        return $image;
    }
    private function portableImage(EncodedStream $stream, int $page, float $logicalWidth, float $logicalHeight, ?GraphicProgram $source = null): ImageContent|GraphicContent
    {
        $dictionary = $stream->dictionary();
        $pixelWidth = $this->values->value($dictionary, 'Width');
        $pixelHeight = $this->values->value($dictionary, 'Height');
        if (!$pixelWidth instanceof IntegerType || !$pixelHeight instanceof IntegerType || $pixelWidth->integer() <= 0 || $pixelHeight->integer() <= 0) {
            throw new ReadingException('An image requires positive integer sample dimensions.');
        }
        $width = $pixelWidth->integer();
        $height = $pixelHeight->integer();
        $filter = $this->values->value($dictionary, 'Filter');
        if ($filter instanceof ArrayType && count($filter) === 1) {
            $filter = $this->values->document->resolve($filter->items[0]);
        }
        $color = $this->values->name($dictionary->get('ColorSpace'));
        $bits = $this->values->integer($dictionary->get('BitsPerComponent'), 8);
        if ($filter instanceof NameType && $filter->value === 'DCTDecode' && $dictionary->get('SMask') === null && $dictionary->get('Mask') === null && $dictionary->get('Decode') === null && in_array($color, ['DeviceGray', 'DeviceRGB'], true)) {
            $bytes = $stream->encodedBytes();
            if (!str_starts_with($bytes, "\xff\xd8")) {
                throw new ReadingException('A DCT image is missing its JPEG signature.');
            }
            $this->values->budget->consume('assets', strlen($bytes));
            return new ImageContent($bytes, 'image/jpeg', $logicalWidth, $logicalHeight, $page, $source);
        }
        if (in_array($color, ['DeviceRGB', 'DeviceGray'], true) && $bits === 8 && $dictionary->get('SMask') === null && $dictionary->get('Mask') === null && $dictionary->get('Decode') === null && $width <= 65535 && $height <= 65535) {
            try {
                $pixels = $this->values->document->streamData($stream);
            } catch (\PDF\Reading\UnsupportedPdfFeature $exception) {
                return new GraphicContent('unsupported-image', $exception->getMessage(), $logicalWidth, $logicalHeight, $page, $source);
            }
            $components = $color === 'DeviceRGB' ? 3 : 1;
            $row = (int) $width * $components;
            if (strlen($pixels) !== $row * (int) $height) {
                throw new ReadingException('Image samples do not match their declared dimensions.');
            }
            $scanlines = '';
            for ($index = 0; $index < (int) $height; ++$index) {
                $scanlines .= "\0" . substr($pixels, $index * $row, $row);
            }
            $compressed = gzcompress($scanlines);
            if ($compressed === false) {
                throw new ReadingException('PNG sample compression failed.');
            }
            $bytes = "\x89PNG\r\n\x1a\n" . $this->chunk('IHDR', pack('NNCCCCC', (int) $width, (int) $height, 8, $components === 3 ? 2 : 0, 0, 0, 0)) . $this->chunk('IDAT', $compressed) . $this->chunk('IEND', '');
            $this->values->budget->consume('assets', strlen($bytes));
            return new ImageContent($bytes, 'image/png', $logicalWidth, $logicalHeight, $page, $source);
        }
        return new GraphicContent('unsupported-image', 'Image conversion requires an explicit decoder for filters, masks, color spaces or sample depths.', $logicalWidth, $logicalHeight, $page, $source);
    }
    private function chunk(string $type, string $bytes): string
    {
        return pack('N', strlen($bytes)) . $type . $bytes . pack('N', crc32($type . $bytes));
    }

    /**
     * @param null|array{float, float, float, float} $boundingBox
     * @return list<SourceNode>
     */
    private function instructions(string $bytes, DictionaryType $resources, ReadPage $page, string $key, int $depth, ?DictionaryType $initialFont = null, ?ContentMatrix $initialMatrix = null, ?array $boundingBox = null, ?GraphicProgram $parent = null, ?EncodedStream $sourceStream = null): array
    {
        $frames = [new MarkedContentFrame(null, new StructureProperties())];
        $font = $initialFont;
        $matrix = $initialMatrix ?? $this->pageMatrix($page);
        $sourceMatrix = $matrix;
        $boundingBox ??= $this->pageBox($page);
        $graphics = [];
        $textObject = false;
        $number = $this->pageNumber($page);
        $instructions = [];
        foreach ((new ContentInstructionReader($this->values->budget, $this->values->document))->read($bytes, $resources) as $instruction) {
            $this->values->budget->consume('nodes');
            $instructions[] = $instruction;
        }
        // Arrays are shared copy-on-write and never mutated: no per-node prefix copies.
        $program = fn (int $first, int $last): GraphicProgram => new GraphicProgram($instructions, $resources, $this->values->document, $sourceMatrix, $number, $boundingBox, $key, $first, $last, $initialFont, $parent, $sourceStream);
        foreach ($instructions as $index => $instruction) {
            $operator = $instruction->operator;
            $operands = $instruction->operands;
            $frame = $this->frame($frames);
            if ($operator === 'BI') {
                if ($instruction->inlineImage === null) {
                    throw new ReadingException('A BI instruction requires its parsed inline image.');
                }
                if (!$frame->suppressContent && !$frame->artifact) {
                    [$logicalWidth, $logicalHeight] = $matrix->unitDimensions();
                    $this->values->budget->consume('nodes');
                    $image = $this->portableImage($instruction->inlineImage->stream(), $number, $logicalWidth, $logicalHeight, $program($index, $index));
                    if ($image instanceof GraphicContent) {
                        $this->values->budget->diagnostic('Inline image data could not be converted; its logical dimensions are retained in a placeholder: ' . $image->instructions);
                    }
                    $frame->children[] = $image;
                }
            } elseif ($operator === 'BMC' || $operator === 'BDC') {
                $tag = $operands[0] ?? null;
                if (!$tag instanceof NameType) {
                    throw new ReadingException('Marked content requires a name tag.');
                }
                $properties = $operands[1] ?? null;
                if ($properties instanceof NameType) {
                    $category = $this->values->dictionary($resources->get('Properties'));
                    $properties = $category === null ? null : $this->values->value($category, $properties->value);
                }
                if ($operator === 'BDC' && !$properties instanceof DictionaryType) {
                    throw new ReadingException('Marked-content properties must resolve to a dictionary.');
                }
                $id = $properties instanceof DictionaryType ? $this->values->value($properties, 'MCID') : null;
                if ($id !== null && (!$id instanceof IntegerType || $id->integer() < 0)) {
                    throw new ReadingException('A marked-content identifier must be a nonnegative integer.');
                }
                $this->values->budget->depth($depth + count($frames));
                $properties = $properties instanceof DictionaryType ? $this->values->properties($properties) : new StructureProperties();
                $frames[] = new MarkedContentFrame($id instanceof IntegerType ? $id->integer() : null, $properties, $frame->artifact || $tag->value === 'Artifact', $frame->suppressContent || $properties->actualText !== null || ($id instanceof IntegerType && isset($this->replacedContent[$key][$id->integer()])), $index);
            } elseif ($operator === 'EMC') {
                if (count($frames) === 1) {
                    throw new ReadingException('An EMC has no open marked-content sequence.');
                }
                $closed = array_pop($frames);
                if (!$closed instanceof MarkedContentFrame) {
                    throw new ReadingException('A marked-content stack is empty.');
                }
                $node = new MarkedContent($closed->children, $closed->properties, $number, $closed->artifact, $program($closed->firstInstruction, $index));
                $this->values->budget->consume('nodes');
                if ($closed->identifier !== null) {
                    if (isset($this->marked[$key][$closed->identifier])) {
                        throw new ReadingException('An MCID occurs more than once in one content container.');
                    }
                    $this->marked[$key][$closed->identifier] = $node;
                }
                $this->frame($frames)->children[] = $node;
            } elseif ($operator === 'q') {
                $graphics[] = new ContentGraphicsState($font, $matrix);
                $this->values->budget->depth($depth + count($graphics));
            } elseif ($operator === 'Q') {
                if ($graphics === []) {
                    throw new ReadingException('A Q operator has no matching q.');
                }
                $restored = array_pop($graphics);
                if (!$restored instanceof ContentGraphicsState) {
                    throw new ReadingException('A graphics-state stack is empty.');
                }
                $font = $restored->font;
                $matrix = $restored->matrix;
            } elseif ($operator === 'cm') {
                $matrix = $matrix->concatenate(ContentMatrix::operands($operands));
            } elseif ($operator === 'BT') {
                if ($textObject) {
                    throw new ReadingException('Text objects may not nest.');
                } $textObject = true;
            } elseif ($operator === 'ET') {
                if (!$textObject) {
                    throw new ReadingException('ET has no matching BT.');
                } $textObject = false;
            } elseif ($operator === 'Tf') {
                $name = $operands[0] ?? null;
                if (!$name instanceof NameType) {
                    throw new ReadingException('Tf requires a font resource name.');
                }
                $category = $this->values->dictionary($resources->get('Font'));
                $font = $category === null ? null : $this->values->dictionary($category->get($name->value));
                if ($font === null) {
                    throw new ReadingException('A text font resource is missing.');
                }
            } elseif (in_array($operator, ['Tj', 'TJ', "'", '"'], true)) {
                if (!$textObject || $font === null) {
                    throw new ReadingException('Text showing requires an active text object and font.');
                }
                if ($frame->artifact || $frame->suppressContent) {
                    continue;
                }
                $shown = $operands[array_key_last($operands)] ?? null;
                $parts = $operator === 'TJ' && $shown instanceof ArrayType ? $shown->items : [$shown];
                $text = '';
                foreach ($parts as $part) {
                    if ($part instanceof StringType) {
                        try {
                            $text .= $this->fonts->decode($font, $part->value);
                        } catch (UnsupportedTextException $exception) {
                            $this->values->budget->consume('nodes');
                            $frame->children[] = new GraphicContent('unmapped-text', $exception->getMessage(), page: $number, source: $program($index, $index));
                        }
                    } elseif (!$part instanceof NumericType || $operator !== 'TJ') {
                        throw new ReadingException('A text-showing operand has an invalid type.');
                    }
                }
                $this->values->budget->consume('nodes');
                if ($text !== '') {
                    $frame->children[] = new TextContent($text, $number, $program($index, $index));
                }
            } elseif ($operator === 'Do') {
                $name = $operands[0] ?? null;
                $category = $this->values->dictionary($resources->get('XObject'));
                $object = $name instanceof NameType && $category !== null ? $this->values->value($category, $name->value) : null;
                if (!$object instanceof EncodedStream) {
                    throw new ReadingException('A Do operator requires an existing XObject stream.');
                }
                if ($frame->suppressContent || $frame->artifact) {
                    continue;
                }
                $subtype = $this->values->name($object->dictionary()->get('Subtype'));
                $this->values->budget->consume('nodes');
                if ($subtype === 'Form') {
                    $source = $program($index, $index);
                    $frame->children[] = new ContentGroup($this->stream($page, $object, $resources, $depth + 1, $font, $matrix, $source), $number, $source);
                } elseif ($subtype === 'Image') {
                    $frame->children[] = $this->image($object, $number, $matrix, $program($index, $index));
                } else {
                    $frame->children[] = new GraphicContent('unsupported-xobject', $subtype ?? 'missing subtype', page: $number, source: $program($index, $index));
                }
            } elseif (in_array($operator, ['S', 's', 'f', 'F', 'f*', 'B', 'B*', 'b', 'b*', 'sh'], true)) {
                if ($frame->suppressContent || $frame->artifact) {
                    continue;
                }
                $this->values->budget->consume('nodes');
                $frame->children[] = new GraphicContent($operator === 'sh' ? 'shading' : 'path', $operator, page: $number, source: $program($index, $index));
            } elseif (!in_array($operator, ['m', 'l', 'c', 'v', 'y', 'h', 're', 'W', 'W*', 'n', 'cm', 'w', 'J', 'j', 'M', 'd', 'ri', 'i', 'gs', 'CS', 'cs', 'SC', 'SCN', 'sc', 'scn', 'G', 'g', 'RG', 'rg', 'K', 'k', 'Tc', 'Tw', 'Tz', 'TL', 'Tr', 'Ts', 'Td', 'TD', 'Tm', 'T*', 'MP', 'DP', 'BX', 'EX', 'd0', 'd1'], true)) {
                $this->values->budget->consume('nodes');
                $frame->children[] = new GraphicContent('unsupported-operator', $operator, page: $number, source: $program($index, $index));
            }
        }
        if (count($frames) !== 1 || $textObject || $graphics !== []) {
            throw new ReadingException('Content has unclosed marked-content, text or graphics state.');
        }
        return $frames[0]->children;
    }
    /** @var array<int, int> */
    private array $pageNumbers = [];
    public function registerPage(ReadPage $page, int $number): void
    {
        $this->pageNumbers[spl_object_id($page)] = $number;
    }
    private function pageNumber(ReadPage $page): int
    {
        return $this->pageNumbers[spl_object_id($page)] ?? throw new \LogicException('Register content pages before adaptation.');
    }
    private function pageKey(ReadPage $page): string
    {
        return 'page:' . $this->values->key($page->reference);
    }
    private function streamKey(ReadPage $page, EncodedStream $stream): string
    {
        return 'stream:' . $this->pageNumber($page) . ':' . spl_object_id($stream);
    }
    /** @param list<MarkedContentFrame> $frames */
    private function frame(array $frames): MarkedContentFrame
    {
        $frame = end($frames);
        return $frame instanceof MarkedContentFrame ? $frame : throw new ReadingException('The content stack is empty.');
    }
    private function pageMatrix(ReadPage $page): ContentMatrix
    {
        $unitValue = $this->values->value($page->dictionary, 'UserUnit');
        if ($unitValue !== null && !$unitValue instanceof NumericType) {
            throw new ReadingException('Page UserUnit must be a positive finite number.');
        }
        $unit = $unitValue instanceof NumericType ? (float) $unitValue->value : 1.0;
        if (!is_finite($unit) || $unit <= 0) {
            throw new ReadingException('Page UserUnit must be a positive finite number.');
        }
        return match ($page->attributes->normalizedRotation()) {
            0 => new ContentMatrix($unit, 0, 0, $unit),
            90 => new ContentMatrix(0, -$unit, $unit, 0),
            180 => new ContentMatrix(-$unit, 0, 0, -$unit),
            270 => new ContentMatrix(0, $unit, -$unit, 0),
            default => throw new ReadingException('Page rotation is invalid.'),
        };
    }

    /** @return array{float, float, float, float} */
    private function pageBox(ReadPage $page): array
    {
        return $this->transformedBox($page->attributes->cropBox, $this->pageMatrix($page));
    }

    /** @return array{float, float, float, float} */
    private function transformedBox(ArrayType $box, ContentMatrix $matrix): array
    {
        $coordinates = $this->values->native($box);
        if (!is_array($coordinates) || count($coordinates) !== 4) {
            throw new ReadingException('A content bounding box requires four numeric coordinates.');
        }
        foreach ($coordinates as $coordinate) {
            if ((!is_int($coordinate) && !is_float($coordinate)) || !is_finite($coordinate)) {
                throw new ReadingException('A content bounding box requires finite numeric coordinates.');
            }
        }
        [$left, $bottom, $right, $top] = array_values($coordinates);
        $x = [];
        $y = [];
        foreach ([[$left, $bottom], [$left, $top], [$right, $bottom], [$right, $top]] as [$a, $b]) {
            $x[] = $matrix->a * $a + $matrix->c * $b + $matrix->e;
            $y[] = $matrix->b * $a + $matrix->d * $b + $matrix->f;
        }
        $bounds = [min($x), min($y), max($x), max($y)];
        if (!is_finite($bounds[0]) || !is_finite($bounds[1]) || !is_finite($bounds[2]) || !is_finite($bounds[3]) || $bounds[2] <= $bounds[0] || $bounds[3] <= $bounds[1]) {
            throw new ReadingException('A content bounding box has degenerate or overflowing transformed dimensions.');
        }
        return $bounds;
    }
}
