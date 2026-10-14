<?php

declare(strict_types=1);

namespace PDF\Html\Rendering;

final readonly class GraphicsExportLimits
{
    public function __construct(public int $maximumObjects = 100000, public int $maximumBytes = 134217728, public int $maximumDepth = 96, public int $maximumInstructions = 2000000)
    {
        foreach ([$maximumObjects, $maximumBytes, $maximumDepth, $maximumInstructions] as $limit) {
            if ($limit < 1) {
                throw new \InvalidArgumentException('Graphics export limits must be positive.');
            }
        }
    }
}
