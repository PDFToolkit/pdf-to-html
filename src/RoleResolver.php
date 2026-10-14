<?php

declare(strict_types=1);

namespace PDF\Html;

use PDF\Html\Model\RoleMapping;
use PDF\Html\Model\StructureElement;

/** Deriving HTML 1.2, physical pages 16-18; stop at a recognized namespace. */
final readonly class RoleResolver
{
    /**
     * @param list<RoleMapping> $mappings
     */
    public function __construct(private array $mappings, private DerivationLimits $limits, private DerivationPolicy $policy)
    {
    }

    public function resolve(StructureElement $element): ResolvedRole
    {
        $type = $element->type;
        $namespace = $element->namespace;
        $identity = $element->namespaceIdentity;
        $original = [];
        $visited = [];
        for ($i = 0; $i < $this->limits->maximumRoleMappings; ++$i) {
            $key = ($identity ?? $namespace ?? '') . "\0" . $type;
            if (isset($visited[$key])) {
                throw new DerivationException('Cyclic structure role mapping.');
            }
            $visited[$key] = true;
            if ($namespace === 'http://iso.org/pdf/ssn' || $namespace === 'http://iso.org/pdf2/ssn'
                || $namespace === 'http://www.w3.org/1998/Math/MathML'
                || ($namespace === 'http://www.w3.org/1999/xhtml' && $this->policy->allowHtmlNamespace)) {
                return new ResolvedRole($type, $namespace, $original);
            }
            $mapping = null;
            foreach ($this->mappings as $candidate) {
                if ($candidate->type === $type && $candidate->namespace === $namespace && $candidate->namespaceIdentity === $identity) {
                    $mapping = $candidate;
                    break;
                }
            }
            if ($mapping === null) {
                if ($namespace !== null) {
                    throw new DerivationException('An unrecognized structure namespace has no role mapping.');
                }
                return new ResolvedRole($type, null, $original);
            }
            $original[] = $type;
            $type = $mapping->targetType;
            $namespace = $mapping->targetNamespace;
            $identity = $mapping->targetNamespaceIdentity;
        }
        throw new DerivationException('The structure role mapping limit was exceeded.');
    }
}
