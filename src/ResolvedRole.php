<?php

declare(strict_types=1);

namespace PDF\Html;

final readonly class ResolvedRole
{
    /**
     * @param list<string> $original
     */
    public function __construct(public string $type, public ?string $namespace, public array $original)
    {
    }
}
