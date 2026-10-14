<?php

declare(strict_types=1);

namespace PDF\Html\Tests;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PDF\Html\Forms\FieldType;
use PDF\Html\HtmlDeriver;
use PDF\Html\Model\AttributeObject;
use PDF\Html\Model\ContentGroup;
use PDF\Html\Model\FormField;
use PDF\Html\Model\MarkedContent;
use PDF\Html\Model\RoleMapping;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\StructureProperties;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\TextContent;
use PDF\Html\Model\Widget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Normative label cases from Deriving HTML 1.2, physical pages 28, 29 and 37. */
final class LabelDerivationTest extends TestCase
{
    public function testIndirectFormLabelsAreAssociatedThroughStructureAndContentGroups(): void
    {
        $label = new StructureElement('label', 'FieldLabel', [new TextContent('Name')], 'urn:custom', properties: new StructureProperties(id: 'person-label'));
        $form = new StructureElement('form', 'Form', [
            new StructureElement('group', 'Div', [new ContentGroup([new MarkedContent([$label])])]),
            new Widget(new FormField('Person.Name', FieldType::Text)),
        ], properties: new StructureProperties(id: 'person'));
        $document = new TaggedDocument('labels.pdf', [$form], roleMappings: [new RoleMapping('FieldLabel', 'urn:custom', 'Lbl', 'http://iso.org/pdf2/ssn')]);
        $xpath = $this->derive($document);

        $derivedLabel = $this->element($xpath, 'person-label');
        self::assertSame('label', $derivedLabel->tagName);
        self::assertSame('person', $derivedLabel->getAttribute('for'));
        self::assertSame('Name', $derivedLabel->textContent);
        self::assertSame('input', $this->element($xpath, $derivedLabel->getAttribute('for'))->tagName);
    }

    public function testNestedInteractiveFormsKeepTheirOwnLabelTargets(): void
    {
        $inner = new StructureElement('inner-form', 'Form', [
            new StructureElement('inner-group', 'Div', [$this->label('inner-label')]),
            new Widget(new FormField('Inner', FieldType::Text)),
        ], properties: new StructureProperties(id: 'inner'));
        $outer = new StructureElement('outer-form', 'Form', [
            $this->label('outer-label'),
            $inner,
            new Widget(new FormField('Outer', FieldType::Text)),
        ], properties: new StructureProperties(id: 'outer'));
        $xpath = $this->derive(new TaggedDocument('labels.pdf', [$outer]));

        self::assertSame('outer', $this->element($xpath, 'outer-label')->getAttribute('for'));
        self::assertSame('inner', $this->element($xpath, 'inner-label')->getAttribute('for'));
    }

    public function testNonInteractiveNestedFormDoesNotInheritTheOuterFieldTarget(): void
    {
        $inner = new StructureElement('inner-form', 'Form', [$this->label('inner-label')]);
        $outer = new StructureElement('outer-form', 'Form', [$inner, new Widget(new FormField('Outer', FieldType::Text))], properties: new StructureProperties(id: 'outer'));
        $xpath = $this->derive(new TaggedDocument('labels.pdf', [$outer]));

        self::assertSame('label', $this->element($xpath, 'inner-label')->tagName);
        self::assertFalse($this->element($xpath, 'inner-label')->hasAttribute('for'));
    }

    public function testAnExplicitHtmlLabelKeepsItsAuthorProvidedTarget(): void
    {
        $label = new StructureElement('html-label', 'label', [new TextContent('Explicit')], 'http://www.w3.org/1999/xhtml', properties: new StructureProperties(id: 'html-label'), attributes: [new AttributeObject('HTML-5.00', ['for' => 'other'])]);
        $form = new StructureElement('form', 'Form', [$label, new Widget(new FormField('Field', FieldType::Text))]);
        $other = new StructureElement('other', 'input', [], 'http://www.w3.org/1999/xhtml', properties: new StructureProperties(id: 'other'), attributes: [new AttributeObject('HTML-5.00', ['type' => 'text'])]);
        $xpath = $this->derive(new TaggedDocument('labels.pdf', [$form, $other]));

        self::assertSame('other', $this->element($xpath, 'html-label')->getAttribute('for'));
    }

    #[DataProvider('formBlockExceptions')]
    public function testFormLabelSpecialDirectChildrenRequireADiv(string $type): void
    {
        $label = $this->label('form-label', [new StructureElement('child', $type, [new TextContent('Content')])]);
        $form = new StructureElement('form', 'Form', [new StructureElement('group', 'Div', [$label]), new Widget(new FormField('Field', FieldType::Text))]);
        $xpath = $this->derive(new TaggedDocument('labels.pdf', [$form]));

        self::assertSame('div', $this->element($xpath, 'form-label')->tagName);
        self::assertFalse($this->element($xpath, 'form-label')->hasAttribute('for'));
    }

    /** @return iterable<string, array{string}> */
    public static function formBlockExceptions(): iterable
    {
        foreach (['Form', 'Figure', 'Formula', 'Caption'] as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('labelContexts')]
    public function testBlockLabelMappingDependsOnItsSemanticContext(string $parentType, bool $description, string $expectedTag): void
    {
        $label = $this->label('context-label', [new TextContent('Prefix'), new StructureElement('block', 'P', [new TextContent('Block')])]);
        $parent = new StructureElement('parent', $parentType, [$label]);
        $root = match ($parentType) {
            'LI' => new StructureElement('list', 'L', [$parent], attributes: $description ? [new AttributeObject('List', ['ListNumbering' => 'Description'])] : []),
            'TOCI' => new StructureElement('toc', 'TOC', [$parent]),
            'Caption' => new StructureElement('figure', 'Figure', [$parent]),
            default => $parent,
        };
        $xpath = $this->derive(new TaggedDocument('labels.pdf', [$root]));

        self::assertSame($expectedTag, $this->element($xpath, 'context-label')->tagName);
    }

    /** @return iterable<string, array{string, bool, string}> */
    public static function labelContexts(): iterable
    {
        yield 'other parent remains span' => ['Div', false, 'span'];
        yield 'ordinary list block label' => ['LI', false, 'div'];
        yield 'description list term' => ['LI', true, 'dt'];
        yield 'heading block label' => ['H1', false, 'div'];
        yield 'caption block label' => ['Caption', false, 'div'];
        yield 'table of contents block label' => ['TOCI', false, 'div'];
    }

    public function testHeadingLabelRecognizesAStandardGroupingBlockChild(): void
    {
        $label = $this->label('heading-label', [new StructureElement('article', 'Art', [new TextContent('Content')])]);
        $xpath = $this->derive(new TaggedDocument('labels.pdf', [new StructureElement('heading', 'H1', [$label])]));

        self::assertSame('div', $this->element($xpath, 'heading-label')->tagName);
    }

    /** @param list<\PDF\Html\Model\SourceNode> $children */
    private function label(string $id, array $children = []): StructureElement
    {
        return new StructureElement($id, 'Lbl', $children === [] ? [new TextContent('Name')] : $children, properties: new StructureProperties(id: $id));
    }

    private function derive(TaggedDocument $document): DOMXPath
    {
        $html = (new HtmlDeriver())->derive($document)->html;
        $dom = new DOMDocument();
        self::assertTrue($dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING));
        return new DOMXPath($dom);
    }

    private function element(DOMXPath $xpath, string $id): DOMElement
    {
        $nodes = $xpath->query('//*[@id="' . $id . '"]');
        self::assertNotFalse($nodes);
        self::assertSame(1, $nodes->length);
        $node = $nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $node);
        return $node;
    }
}
