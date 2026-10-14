<?php

declare(strict_types=1);

namespace PDF\Html;

final readonly class DerivationLimits
{
    public function __construct(
        public int $maximumNodes = 50000,
        public int $maximumDepth = 128,
        public int $maximumOutputBytes = 33554432,
        public int $maximumAssetBytes = 16777216,
        public int $maximumRoleMappings = 128,
    ) {
        foreach (get_object_vars($this) as $value) {
            if ($value < 1) {
                throw new \InvalidArgumentException('Derivation limits must be positive.');
            }
        }
    }
}
