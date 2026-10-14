<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Html\Model\SourceNode;
use PDF\Html\Model\StructureProperties;

/** Mutable only while one explicitly scoped instruction sequence is being read. */
final class MarkedContentFrame
{
    /** @var list<SourceNode> */
    public array $children = [];
    public function __construct(public readonly ?int $identifier, public readonly StructureProperties $properties, public readonly bool $artifact = false, public readonly bool $suppressContent = false, public readonly int $firstInstruction = 0)
    {
    }
}
