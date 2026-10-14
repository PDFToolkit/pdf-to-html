<?php

declare(strict_types=1);

namespace PDF\Html\Model;

final readonly class AttributeObject
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(public string $owner, public array $values, public ?string $namespace = null, public ?string $namespaceIdentity = null)
    {
    }
}
