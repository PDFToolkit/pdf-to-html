<?php

declare(strict_types=1);

namespace PDF\Html\Model;

final readonly class AssociatedFile
{
    public function __construct(public string $filename, public string $mediaType, public string $relationship, public ?string $bytes = null, public ?string $url = null)
    {
    }
}
