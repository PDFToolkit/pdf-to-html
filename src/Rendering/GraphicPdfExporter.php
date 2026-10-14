<?php

declare(strict_types=1);

namespace PDF\Html\Rendering;

use PDF\FileStructure;
use PDF\Html\DerivationException;
use PDF\Html\Model\ContentGroup;
use PDF\Html\Model\GraphicContent;
use PDF\Html\Model\GraphicProgram;
use PDF\Html\Model\MarkedContent;
use PDF\Html\Model\SourceNode;
use PDF\Html\Model\StructureElement;
use PDF\Document\ResourceDictionary;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;

/** Produce a bounded one-page PDF of precisely selected content, with its original resource graph. */
final readonly class GraphicPdfExporter
{
    public function __construct(private GraphicsExportLimits $limits = new GraphicsExportLimits())
    {
    }

    public function export(GraphicContent|ContentGroup $content): RenderingDocument
    {
        $sources = [];
        $this->collect($content, $sources, 0);
        if ($sources === []) {
            throw new DerivationException('Graphics export requires original PDF programs, not textual descriptions.');
        }
        $programs = [];
        $ranges = [];
        $root = null;
        foreach ($sources as $source) {
            $ancestry = 0;
            do {
                if (++$ancestry > $this->limits->maximumDepth) {
                    throw new DerivationException('The graphics caller chain exceeds its export limit.');
                }
                $key = $source->container;
                $previous = $programs[$key] ?? null;
                if ($previous !== null && ($previous->document !== $source->document || $previous->page !== $source->page || $previous->resources !== $source->resources || $previous->initialMatrix != $source->initialMatrix)) {
                    throw new DerivationException('A graphics container has conflicting source contexts.');
                }
                $programs[$key] = $source;
                $ranges[$key][] = [$source->firstInstruction, $source->lastInstruction];
                if ($source->parent === null) {
                    if ($root !== null && $root->container !== $source->container) {
                        throw new DerivationException('One raster image cannot silently combine independent pages or containers.');
                    }
                    $root = $source;
                }
                $source = $source->parent;
            } while ($source !== null);
        }
        if ($root === null || $root->boundingBox === null) {
            throw new DerivationException('Graphics export requires the effective source page box.');
        }
        $writer = new ContentProgramWriter($this->limits);
        $overrides = [];
        $contentBytes = '';
        foreach ($programs as $key => $program) {
            $bytes = $writer->write($program, $ranges[$key]);
            if ($program->parent === null) {
                $contentBytes = $bytes;
            } elseif ($program->stream !== null) {
                $overrides[spl_object_id($program->stream)] = $bytes;
            } else {
                throw new DerivationException('A nested graphics program requires its original Form stream.');
            }
        }
        $import = new GraphicsImportSession($root->document, $this->limits, $overrides);
        $resourceEntries = [];
        foreach ($root->resources->entries() as $entry) {
            $resourceEntries[] = new Entry($entry->key, $import->import($root->document->resolve($entry->value)));
        }
        $resources = new ResourceDictionary(...$resourceEntries);
        $matrix = $root->initialMatrix;
        $context = new SerializationContext();
        $coefficients = array_map(static fn (float $coefficient): string => (new NumericType($coefficient))->toPdf($context), [$matrix->a, $matrix->b, $matrix->c, $matrix->d, $matrix->e, $matrix->f]);
        $contentBytes = "q\n".implode(' ', $coefficients)." cm\n".$contentBytes."Q\n";
        $import->retain(strlen($contentBytes));
        // Reader boxes are already in the physical coordinate system after initialMatrix.
        $box = $root->boundingBox;
        if ($content instanceof ContentGroup && $content->viewport !== null) {
            $viewport = $content->viewport->transformed($matrix);
            $box = [$viewport->left, $viewport->bottom, $viewport->right, $viewport->top];
        }
        $rectangle = new Rectangle(...array_map(static fn (float $number): NumericType => new NumericType($number), $box));
        $page = new PageObject($rectangle, $resources, new StreamObject($contentBytes));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        $bytes = (new FileStructure(new FileHeader('2.0'), new FileBody($catalog), new IndirectReference($catalog)))->toPdf();
        if (strlen($bytes) > $this->limits->maximumBytes) {
            throw new DerivationException('The final isolated graphics PDF exceeds its byte limit.');
        }
        return new RenderingDocument($bytes, $box[2] - $box[0], $box[3] - $box[1], $root->page);
    }

    /** @param list<GraphicProgram> $sources */
    private function collect(SourceNode $content, array &$sources, int $depth): void
    {
        if ($depth > $this->limits->maximumDepth || count($sources) >= $this->limits->maximumObjects) {
            throw new DerivationException('The selected graphics group exceeds its export limits.');
        }
        if ($content instanceof MarkedContent && $content->artifact) {
            return;
        }
        if (property_exists($content, 'source') && $content->source instanceof GraphicProgram) {
            $sources[] = $content->source;
            return;
        }
        if ($content instanceof ContentGroup || $content instanceof MarkedContent || $content instanceof StructureElement) {
            foreach ($content->children as $child) {
                $this->collect($child, $sources, $depth + 1);
            }
        }
    }
}
