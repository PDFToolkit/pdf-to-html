<?php

declare(strict_types=1);

namespace PDF\Html\Model;

use PDF\Html\Reading\ContentInstruction;
use PDF\Html\Reading\ContentMatrix;
use PDF\Reading\EncodedStream;
use PDF\Reading\ReadDocument;
use PDF\Syntax\Object\DictionaryType;

/**
 * One shared content program and an inclusive selected range, with its original rendering context.
 * boundingBox is in physical PDF points after initialMatrix; it must not be transformed a second time.
 */
final readonly class GraphicProgram
{
    /**
     * @param list<ContentInstruction> $instructions
     * @param null|array{float, float, float, float} $boundingBox
     */
    public function __construct(
        public array $instructions,
        public DictionaryType $resources,
        public ReadDocument $document,
        public ContentMatrix $initialMatrix,
        public int $page,
        public ?array $boundingBox,
        public string $container,
        public int $firstInstruction,
        public int $lastInstruction,
        public ?DictionaryType $initialFont = null,
        public ?self $parent = null,
        public ?EncodedStream $stream = null,
    ) {
        if ($page < 1 || $container === '' || $firstInstruction < 0 || $lastInstruction < $firstInstruction || $lastInstruction >= count($instructions)) {
            throw new \InvalidArgumentException('A graphic program requires a page, container and valid inclusive instruction range.');
        }
        if ($boundingBox !== null) {
            if (!array_is_list($boundingBox) || count($boundingBox) !== 4) {
                throw new \InvalidArgumentException('A graphic program bounding box requires exactly four coordinates.');
            }
            foreach ($boundingBox as $coordinate) {
                if ((!is_int($coordinate) && !is_float($coordinate)) || !is_finite($coordinate)) {
                    throw new \InvalidArgumentException('A graphic program bounding box requires finite coordinates.');
                }
            }
            if ($boundingBox[2] <= $boundingBox[0] || $boundingBox[3] <= $boundingBox[1]) {
                throw new \InvalidArgumentException('A graphic program bounding box requires positive dimensions.');
            }
        }
    }

    public function withRange(int $firstInstruction, int $lastInstruction): self
    {
        return new self($this->instructions, $this->resources, $this->document, $this->initialMatrix, $this->page, $this->boundingBox, $this->container, $firstInstruction, $lastInstruction, $this->initialFont, $this->parent, $this->stream);
    }
}
