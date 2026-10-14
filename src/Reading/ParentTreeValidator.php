<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Reading\ObjectReference;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NullType;

/** ISO 32000-2 14.7.5.4, a precondition of Deriving HTML 1.2 sections 4.1/4.3.1. */
final class ParentTreeValidator
{
    /** @var list<StructureAssociation> */
    private array $associations = [];
    /** @var array<int, true> */
    private array $elements = [];

    public function __construct(private readonly SourceValues $values, private readonly StructureValidationMode $mode = StructureValidationMode::Permissive)
    {
    }

    public function element(DictionaryType $element): void
    {
        $this->elements[spl_object_id($element)] = true;
    }

    public function associate(DictionaryType $container, DictionaryType $element, ?int $mcid = null): void
    {
        $this->values->budget->consume('nodes');
        $this->associations[] = new StructureAssociation($container, $element, $mcid);
    }

    public function validate(DictionaryType $root): void
    {
        $tree = $root->get('ParentTree');
        if ($tree === null) {
            if ($this->associations !== []) {
                $this->issue('The structure tree has content items but no ParentTree.');
            }
            return;
        }
        /** @var array<int, BasicType> $entries */
        $entries = [];
        /** @var array<int, BasicType> $entrySources */
        $entrySources = [];
        $previous = -1;
        foreach ((new DocumentMetadataReader($this->values))->tree($tree, 'Nums') as [$key, $value]) {
            $key = $this->values->document->resolve($key);
            if (!$key instanceof IntegerType || $key->integer() < 0) {
                $this->issue('A ParentTree key must be a nonnegative integer.');
                continue;
            }
            $number = $key->integer();
            if (array_key_exists($number, $entries)) {
                $this->issue('ParentTree keys must be unique.');
                continue;
            }
            if ($number <= $previous) {
                $this->issue('ParentTree number-tree keys must be ordered.');
            }
            $previous = $number;
            $entries[$number] = $this->values->document->resolve($value);
            $entrySources[$number] = $value;
        }
        $next = $this->values->value($root, 'ParentTreeNextKey');
        if ($next !== null && (!$next instanceof IntegerType || $next->integer() < 0 || ($entries !== [] && $next->integer() <= max(array_keys($entries))))) {
            $this->issue('ParentTreeNextKey must be an integer greater than every ParentTree key.');
        }
        /** @var array<int, array<int, DictionaryType>|DictionaryType> $expected */
        $expected = [];
        /** @var array<int, int> $containers */
        $containers = [];
        foreach ($this->associations as $association) {
            $container = $association->container;
            $key = $association->mcid === null ? 'StructParent' : 'StructParents';
            if ($container->get('StructParent') !== null && $container->get('StructParents') !== null) {
                $this->issue('A content object cannot have both StructParent and StructParents.');
            }
            $number = $this->values->value($container, $key);
            if (!$number instanceof IntegerType || $number->integer() < 0) {
                $this->issue('A structural content container requires a nonnegative /' . $key . '.');
                continue;
            }
            $number = $number->integer();
            $identity = spl_object_id($container);
            if (isset($containers[$number]) && $containers[$number] !== $identity) {
                $this->issue('A ParentTree key is shared by distinct content containers.');
                continue;
            }
            $containers[$number] = $identity;
            if ($association->mcid === null) {
                if (array_key_exists($number, $expected)) {
                    $this->issue('An object content item has more than one forward structure association.');
                }
                $expected[$number] = $association->element;
            } else {
                $entry = $expected[$number] ?? [];
                if (!is_array($entry)) {
                    $this->issue('A ParentTree key cannot identify both an object and an MCID container.');
                    continue;
                }
                if (isset($entry[$association->mcid])) {
                    $this->issue('A marked-content item has more than one forward structure association.');
                }
                $entry[$association->mcid] = $association->element;
                $expected[$number] = $entry;
            }
        }
        foreach ($expected as $key => $parents) {
            $actual = $entries[$key] ?? null;
            if (is_array($parents)) {
                if (!$actual instanceof ArrayType) {
                    $this->issue('An MCID container requires a ParentTree array at key ' . $key . '.');
                    continue;
                }
                foreach ($parents as $mcid => $parent) {
                    $value = $actual->items[$mcid] ?? null;
                    if ($value === null || $this->values->dictionary($value) !== $parent) {
                        $this->issue('ParentTree key ' . $key . ' MCID ' . $mcid . ' does not point to its forward parent.');
                    }
                }
            } elseif (!$actual instanceof DictionaryType || $actual !== $parents) {
                $this->issue('ParentTree key ' . $key . ' does not point to its object content item\'s forward parent.');
            }
        }
        // Check the reverse direction too: no non-null entry may invent an association.
        foreach ($entries as $key => $actual) {
            $parents = $expected[$key] ?? null;
            if ($actual instanceof ArrayType) {
                foreach ($actual->items as $mcid => $value) {
                    $this->values->budget->consume('nodes');
                    $resolved = $this->values->document->resolve($value);
                    if ($resolved instanceof NullType) {
                        continue;
                    }
                    $this->target($value, $resolved);
                    if (!is_array($parents) || !isset($parents[$mcid]) || $parents[$mcid] !== $resolved) {
                        $this->issue('ParentTree key ' . $key . ' MCID ' . $mcid . ' has no matching forward association.');
                    }
                }
            } else {
                $this->target($entrySources[$key], $actual);
                if (!$parents instanceof DictionaryType || $parents !== $actual) {
                    $this->issue('ParentTree key ' . $key . ' has no matching object forward association.');
                }
            }
        }
    }

    private function target(BasicType $source, BasicType $resolved, bool $requireReference = true): void
    {
        if ($requireReference && !$source instanceof ObjectReference) {
            $this->issue('A non-null ParentTree array slot must be an indirect structure-element reference.');
        }
        if (!$resolved instanceof DictionaryType || !isset($this->elements[spl_object_id($resolved)])) {
            $this->issue('A ParentTree target is not a structure element in the forward structure tree.');
        }
    }

    private function issue(string $message): void
    {
        if ($this->mode === StructureValidationMode::Strict) {
            throw new ReadingException($message);
        }
        $this->values->budget->diagnostic('Inverse structure association: ' . $message);
    }
}
