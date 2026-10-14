<?php

declare(strict_types=1);

namespace PDF\Html\Model;

final readonly class RoleMapping
{
    public function __construct(public string $type, public ?string $namespace, public string $targetType, public ?string $targetNamespace = null, public ?string $namespaceIdentity = null, public ?string $targetNamespaceIdentity = null)
    {
    }
}
