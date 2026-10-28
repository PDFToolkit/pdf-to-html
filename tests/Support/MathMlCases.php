<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Support;

use PDF\Html\Model\AssociatedFile;
use PDF\Html\Model\AttributeObject;
use PDF\Html\Model\RoleMapping;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\StructureProperties;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\TextContent;

final readonly class MathMlCases
{
    public const NAMESPACE = 'http://www.w3.org/1998/Math/MathML';

    /** @return array<string, array{document: TaggedDocument, expectedDisplay: string, htmlDisplays?: array<string, string>}> */
    public function all(): array
    {
        $cases = [];
        foreach (['Inline' => 'inline', 'Block' => 'block'] as $placement => $expected) {
            $attributes = [new AttributeObject('Layout', ['Placement' => $placement])];
            $cases['direct-' . $expected] = $this->case($this->math($attributes), $expected);
            $cases['descendant-' . $expected] = $this->case($this->math($attributes, descendants: true), $expected);
            $cases['shared-class-' . $expected] = [
                'document' => new TaggedDocument('class.pdf', [
                    $this->math(properties: new StructureProperties(classes: ['shared']), descendants: true),
                    new StructureElement('ordinary', 'P', [new TextContent('Ordinary HTML')], properties: new StructureProperties(classes: ['shared'])),
                ], classMap: ['shared' => $attributes]),
                'expectedDisplay' => $expected,
            ];
            $mapped = new StructureElement('mapped', 'CustomMath', $this->children(), attributes: $attributes);
            $cases['role-mapped-' . $expected] = [
                'document' => new TaggedDocument('mapped.pdf', [$mapped], roleMappings: [new RoleMapping('CustomMath', null, 'math', self::NAMESPACE)]),
                'expectedDisplay' => $expected,
            ];
            foreach (['Alternative', 'Supplement'] as $relationship) {
                $formula = new StructureElement('formula', 'Formula', [new TextContent('PDF formula fallback')], attributes: $attributes, associatedFiles: [
                    new AssociatedFile('formula.mathml', 'application/mathml+xml', $relationship, $this->fragment()),
                ]);
                $cases[strtolower($relationship) . '-' . $expected] = $this->case($formula, $expected);
            }
        }
        $cases['shared-class-html-precedence'] = [
            'document' => new TaggedDocument('class-precedence.pdf', [new StructureElement('formula', 'Formula', associatedFiles: [
                new AssociatedFile('formula.html', 'text/html', 'Alternative', '<div>' . $this->fragment() . '<span id="html-class-priority" class="spacing explicit">HTML class priority</span></div>'),
            ])], classMap: [
                'spacing' => [new AttributeObject('Layout', ['SpaceBefore' => 3])],
                'explicit' => [new AttributeObject('CSS-3.00', ['display' => 'inline'])],
            ]),
            'expectedDisplay' => 'inline',
            'htmlDisplays' => ['#html-class-priority' => 'inline'],
        ];
        $cases['default-inline'] = $this->case($this->math(), 'inline');
        $cases['formula-child'] = $this->case(new StructureElement('formula', 'Formula', [$this->math()], associatedFiles: [
            new AssociatedFile('ignored.mathml', 'application/mathml+xml', 'Alternative', '<math><mi>ignored</mi></math>'),
        ]), 'inline');
        $cases['margin-default'] = $this->case($this->math([new AttributeObject('Layout', ['SpaceBefore' => 3, 'StartIndent' => 2])]), 'inline');
        $cases['margin-class'] = [
            'document' => new TaggedDocument('margin.pdf', [$this->math(properties: new StructureProperties(classes: ['shared']))], classMap: [
                'shared' => [new AttributeObject('Layout', ['SpaceBefore' => 3])],
            ]),
            'expectedDisplay' => 'inline',
        ];
        foreach (['inline', 'block'] as $display) {
            $math = new StructureElement('math', 'math', $this->children(), self::NAMESPACE, attributes: [
                new AttributeObject('Layout', ['Placement' => $display === 'inline' ? 'Block' : 'Inline']),
                new AttributeObject('NSO', ['display' => $display], self::NAMESPACE, 'math-namespace'),
            ], namespaceIdentity: 'math-namespace');
            $cases['explicit-' . $display] = $this->case($math, $display);
            $cases['fragment-' . $display] = $this->case(new StructureElement('formula', 'Formula', attributes: [
                new AttributeObject('Layout', ['Placement' => $display === 'inline' ? 'Block' : 'Inline']),
            ], associatedFiles: [new AssociatedFile('formula.mathml', 'application/mathml+xml', 'Alternative', $this->fragment($display))]), $display);
        }
        return $cases;
    }

    /** @param list<AttributeObject> $attributes */
    public function math(array $attributes = [], ?StructureProperties $properties = null, bool $descendants = false): StructureElement
    {
        return new StructureElement('math', 'math', $this->children($descendants), self::NAMESPACE, $properties, $attributes);
    }

    /** @return list<StructureElement> */
    private function children(bool $layout = false): array
    {
        $attributes = $layout ? [new AttributeObject('Layout', ['Placement' => 'Inline', 'SpaceBefore' => 1])] : [];
        $leaf = static fn (string $key, string $tag, string $text): StructureElement => new StructureElement($key, $tag, [new TextContent($text)], self::NAMESPACE);
        return [
            $leaf('energy', 'mi', 'E'), $leaf('equals', 'mo', '='), $leaf('mass', 'mi', 'm'),
            new StructureElement('superscript', 'msup', [$leaf('speed', 'mi', 'c'), $leaf('power', 'mn', '2')], self::NAMESPACE, new StructureProperties(classes: $layout ? ['shared'] : []), $attributes),
            $leaf('plus', 'mo', '+'),
            new StructureElement('fraction', 'mfrac', [$leaf('numerator', 'mn', '1'), $leaf('denominator', 'mn', '2')], self::NAMESPACE, attributes: $attributes),
        ];
    }

    public function fragment(?string $display = null): string
    {
        return '<math xmlns="' . self::NAMESPACE . '"' . ($display === null ? '' : ' display="' . $display . '"') . '><mi>E</mi><mo>=</mo><mi>m</mi><msup><mi>c</mi><mn>2</mn></msup><mo>+</mo><mfrac><mn>1</mn><mn>2</mn></mfrac></math>';
    }

    /** @return array{document: TaggedDocument, expectedDisplay: string} */
    private function case(StructureElement $element, string $display): array
    {
        return ['document' => new TaggedDocument('math.pdf', [$element]), 'expectedDisplay' => $display];
    }
}
