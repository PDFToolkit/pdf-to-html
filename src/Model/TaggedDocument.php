<?php

declare(strict_types=1);

namespace PDF\Html\Model;

final readonly class TaggedDocument
{
    /**
     * @param list<StructureElement> $structure
     * @param array<string, list<AttributeObject>> $classMap
     * @param list<RoleMapping> $roleMappings
     * @param list<AssociatedFile> $associatedFiles
     * @param array<int, string> $pageLabels
     * @param list<string> $documentScripts
     * @param array<int, array<string, string>> $pageScripts
     * @param list<string> $calculationOrder
     * @param list<string> $sourceDiagnostics
     */
    public function __construct(public string $filename, public array $structure, public ?string $language = null, public ?string $title = null, public array $classMap = [], public array $roleMappings = [], public array $associatedFiles = [], public bool $hasAcroForm = false, public ?AuthenticatedPermissions $permissions = null, public array $pageLabels = [], public array $documentScripts = [], public array $pageScripts = [], public array $sourceDiagnostics = [], public array $calculationOrder = [])
    {
    }
}
