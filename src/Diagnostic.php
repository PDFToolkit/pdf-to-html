<?php

declare(strict_types=1);

namespace PDF\Html;

final readonly class Diagnostic
{
    public function __construct(public string $code, public string $message, public ?string $source = null)
    {
    }
}
