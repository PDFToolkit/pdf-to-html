<?php

declare(strict_types=1);

namespace PDF\Html;

final readonly class Asset
{
    public function __construct(public string $filename, public string $mediaType, public string $bytes)
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/D', $filename) !== 1 || str_contains($filename, '..')) {
            throw new \InvalidArgumentException('Asset names must be safe basenames.');
        }
    }
}
