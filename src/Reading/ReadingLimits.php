<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

/** Cumulative ceilings for one source adaptation, additional to the PDF reader limits. */
final readonly class ReadingLimits
{
    public function __construct(
        public int $maximumDepth = 96,
        public int $maximumNodes = 100000,
        public int $maximumInstructions = 2000000,
        public int $maximumContentBytes = 134217728,
        public int $maximumTextBytes = 33554432,
        public int $maximumMappings = 1000000,
        public int $maximumAssetsBytes = 67108864,
        public int $maximumPages = 10000,
    ) {
        foreach (get_object_vars($this) as $value) {
            if ($value < 1) {
                throw new \InvalidArgumentException('Source adaptation limits must be positive.');
            }
        }
    }
}
