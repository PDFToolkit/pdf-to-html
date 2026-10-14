<?php

declare(strict_types=1);

namespace PDF\Html\Tests;

use PDF\Html\ContentRenderer;
use PDF\Html\DerivationException;
use PDF\Html\DerivationPolicy;
use PDF\Html\Forms\FieldType;
use PDF\Html\HtmlDeriver;
use PDF\Html\Model\AssociatedFile;
use PDF\Html\Model\AttributeObject;
use PDF\Html\Model\ContentGroup;
use PDF\Html\Model\FormField;
use PDF\Html\Model\GraphicContent;
use PDF\Html\Model\ImageContent;
use PDF\Html\Model\LinkAnnotation;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\StructureProperties;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\TextContent;
use PDF\Html\Model\Widget;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AdvancedDerivationTest extends TestCase
{
    #[DataProvider('standardRoles')]
    public function testStandardRoleMappings(string $type, string $tag, ?string $parent): void
    {
        $element = new StructureElement('element', $type, [new TextContent('content')], 'http://iso.org/pdf2/ssn');
        $root = $parent === null ? $element : new StructureElement('parent', $parent, [$element], 'http://iso.org/pdf2/ssn');
        if (in_array($type, ['TH', 'TD'], true)) {
            $root = new StructureElement('table', 'Table', [new StructureElement('row', 'TR', [$element])]);
        }
        $html = (new HtmlDeriver())->derive(new TaggedDocument('x.pdf', [$root]))->html;
        self::assertMatchesRegularExpression('/<' . $tag . '\\b[^>]*data-pdf-se-type="' . $type . '"/', $html);
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function standardRoles(): iterable
    {
        foreach (['Art' => 'article', 'Aside' => 'aside', 'BibEntry' => 'p', 'BlockQuote' => 'blockquote', 'Document' => 'div', 'DocumentFragment' => 'div', 'Div' => 'div', 'Part' => 'div', 'Title' => 'div', 'Em' => 'em', 'Strong' => 'strong', 'P' => 'p', 'Span' => 'span', 'Sub' => 'span', 'Index' => 'section', 'Sect' => 'section', 'Quote' => 'q', 'Link' => 'a', 'Reference' => 'a', 'Ruby' => 'ruby', 'RB' => 'span', 'RP' => 'rp', 'RT' => 'rt', 'Warichu' => 'span', 'WT' => 'span', 'WP' => 'span', 'Table' => 'table', 'THead' => 'thead', 'TBody' => 'tbody', 'TFoot' => 'tfoot', 'TR' => 'tr', 'TH' => 'th', 'TD' => 'td', 'TOC' => 'ol', 'TOCI' => 'li', 'L' => 'ul', 'LI' => 'li', 'Lbl' => 'span', 'LBody' => 'div', 'Caption' => 'div', 'FENote' => 'div', 'Formula' => 'div', 'Figure' => 'figure', 'Form' => 'figure', 'Code' => 'code', 'H' => 'p', 'H1' => 'h1', 'H6' => 'h6', 'H7' => 'p'] as $type => $tag) {
            $parent = match ($type) {
                'THead', 'TBody', 'TFoot', 'TR' => 'Table',
                'LI' => 'L', 'TOCI' => 'TOC',
                'RB', 'RP', 'RT' => 'Ruby',
                default => null,
            };
            yield $type => [$type, $tag, $parent];
        }
    }

    public function testPrintFieldNeverCreatesAnInteractiveWidgetOrWidgetScript(): void
    {
        $widget = new Widget(new FormField('printed', FieldType::Text, value: 'printed value', actions: ['V' => 'throw new Error("printed-script");']), caption: 'Printed field');
        $source = new StructureElement('print', 'Form', [$widget], attributes: [new AttributeObject('PrintField', ['Role' => 'tv'])]);
        $result = (new HtmlDeriver(new DerivationPolicy(allowActiveContent: true)))->derive(new TaggedDocument('x.pdf', [$source]));
        $html = $result->html;
        self::assertStringContainsString('<figure', $html);
        self::assertStringContainsString('Printed field', $html);
        self::assertStringNotContainsString('<input', $html);
        self::assertStringNotContainsString('printed-script', $html);
        foreach ($result->assets as $asset) {
            self::assertStringNotContainsString('printed-script', $asset->bytes);
        }
    }

    public function testHtmlNamespaceFieldsUseExplicitAttributesWithoutMappingTheWidget(): void
    {
        $widget = new Widget(new FormField('ignored-pdf-name', FieldType::Text, value: 'PDF value'));
        $source = new StructureElement('input', 'input', [$widget], 'http://www.w3.org/1999/xhtml', attributes: [new AttributeObject('HTML-5.00', ['type' => 'text', 'value' => 'HTML value', 'name' => 'html-name'])]);
        $result = (new HtmlDeriver())->derive(new TaggedDocument('x.pdf', [$source]));
        self::assertStringContainsString('value="HTML value"', $result->html);
        self::assertStringContainsString('name="html-name"', $result->html);
        self::assertStringNotContainsString('ignored-pdf-name', $result->html);
        self::assertContains('html-namespace-widget', array_column($result->diagnostics, 'code'));
    }

    public function testHtmlScriptAssociationIsAnExternalAssetWithUnchangedJavaScript(): void
    {
        $javascript = 'const sample = "</script><p>literal</p>";';
        $source = new StructureElement('script', 'script', [new TextContent('ignored source content')], 'http://www.w3.org/1999/xhtml', associatedFiles: [new AssociatedFile('source.js', 'text/javascript', 'Supplement', $javascript)]);
        $result = (new HtmlDeriver(new DerivationPolicy(allowActiveContent: true)))->derive(new TaggedDocument('x.pdf', [$source]));
        self::assertSame(1, substr_count($result->html, '<script'));
        self::assertStringNotContainsString('ignored source content', $result->html);
        self::assertStringNotContainsString('<p>literal</p>', $result->html);
        self::assertCount(1, $result->assets);
        self::assertSame($javascript, $result->assets[0]->bytes);
        self::assertStringContainsString('src="' . $result->assets[0]->filename . '"', $result->html);
    }

    public function testInvalidUtf8CannotBypassSerializationThroughAScriptAsset(): void
    {
        $this->expectException(DerivationException::class);
        $source = new StructureElement('script', 'script', [], 'http://www.w3.org/1999/xhtml', associatedFiles: [new AssociatedFile('source.js', 'text/javascript', 'Supplement', "const invalid = '\xFF';")]);
        (new HtmlDeriver(new DerivationPolicy(allowActiveContent: true)))->derive(new TaggedDocument('x.pdf', [$source]));
    }

    public function testCodeLinesRemainSeparateInPreformattedOutput(): void
    {
        $source = new StructureElement('code', 'Code', [new StructureElement('line-a', 'Sub', [new TextContent('x = 3')]), new StructureElement('line-b', 'Sub', [new TextContent('y = 7')])]);
        $html = (new HtmlDeriver())->derive(new TaggedDocument('x.pdf', [$source]))->html;
        self::assertStringContainsString("x = 3</code>\n<code", $html);
    }

    public function testGeneratedIdentifiersDoNotCollideWithLaterExplicitIds(): void
    {
        $doc = new TaggedDocument('x.pdf', [new StructureElement('generated', 'P', [new TextContent('one')]), new StructureElement('explicit', 'P', [new TextContent('two')], properties: new StructureProperties(id: 'pdf-se-1'))]);
        $html = (new HtmlDeriver())->derive($doc)->html;
        self::assertSame(1, substr_count($html, 'id="pdf-se-1"'));
        self::assertSame(1, substr_count($html, 'id="pdf-se-2"'));
    }

    public function testSupplementDoesNotDuplicateItsOwningStructureIdentifier(): void
    {
        $figure = new StructureElement('figure', 'Figure', [new TextContent('replaced', 1)], properties: new StructureProperties(id: 'illustration'), associatedFiles: [new AssociatedFile('x.html', 'text/html', 'Supplement', '<span>supplement</span>')]);
        $html = (new HtmlDeriver())->derive(new TaggedDocument('x.pdf', [$figure]))->html;
        self::assertSame(1, substr_count($html, 'id="illustration"'));
        self::assertStringNotContainsString('replaced', $html);
    }

    public function testActualTextStillCreatesThePageNavigationAnchor(): void
    {
        $source = new StructureElement('p', 'P', [new TextContent('original', 3)], properties: new StructureProperties(actualText: 'replacement'));
        $html = (new HtmlDeriver())->derive(new TaggedDocument('x.pdf', [$source]))->html;
        self::assertStringContainsString('id="PDF-Page-3"', $html);
        self::assertStringContainsString('href="#PDF-Page-3"', $html);
    }

    public function testInteractiveFormHasLabelsAndPageNavigation(): void
    {
        $widget = new Widget(new FormField('Person.Name', FieldType::Text, value: 'Ada'), 2, rectangle: [0, 0, 50, 10], pageBox: [0, 0, 100, 100]);
        $source = new StructureElement('field', 'Form', [new StructureElement('label', 'Lbl', [new TextContent('Name')]), $widget], properties: new StructureProperties(id: 'person'));
        $html = (new HtmlDeriver())->derive(new TaggedDocument('x.pdf', [$source]))->html;
        self::assertStringContainsString('for="person"', $html);
        self::assertStringContainsString('id="person"', $html);
        self::assertStringContainsString('id="PDF-Page-2"', $html);
        self::assertStringContainsString('form="acroform_id"', $html);
    }

    public function testSuppressedWidgetsNeverEnterTheTrustedScriptRuntime(): void
    {
        $widget = new Widget(new FormField('secret', FieldType::Text, actions: ['V' => 'throw new Error("private-action");']));
        $doc = new TaggedDocument('x.pdf', [new StructureElement('private', 'Private', [$widget]), new StructureElement('public', 'P', [new TextContent('public')])], hasAcroForm: true);
        $html = (new HtmlDeriver(new DerivationPolicy(allowActiveContent: true)))->derive($doc)->html;
        self::assertStringNotContainsString('private-action', $html);
        self::assertStringNotContainsString('name="secret"', $html);
    }

    public function testHeadSupplementImportsMetadataButNotRefreshAndIgnoresOtherFiles(): void
    {
        $doc = new TaggedDocument('x.pdf', [], associatedFiles: [new AssociatedFile('head.html', 'text/html', 'Supplement', '<meta name="author" content="Author"><meta http-equiv="refresh" content="0;url=https://example.org/">'), new AssociatedFile('ignored.html', 'text/html', 'Supplement', '<meta name="other" content="ignored">')]);
        $result = (new HtmlDeriver())->derive($doc);
        self::assertStringContainsString('<meta name="author" content="Author">', $result->html);
        self::assertStringNotContainsString('http-equiv', $result->html);
        self::assertStringNotContainsString('content="ignored"', $result->html);
    }

    public function testSvgUsesBoundingBoxLogicalDimensionsInTrustedProfile(): void
    {
        $source = new StructureElement('figure', 'Figure', attributes: [new AttributeObject('Layout', ['BBox' => [10, 20, 82, 56]])], associatedFiles: [new AssociatedFile('shape.svg', 'image/svg+xml', 'Alternative', '<svg xmlns="http://www.w3.org/2000/svg"/>')]);
        $html = (new HtmlDeriver(new DerivationPolicy(allowActiveContent: true)))->derive(new TaggedDocument('x.pdf', [$source]))->html;
        self::assertStringContainsString('src="shape.svg"', $html);
        self::assertStringContainsString('width="96" height="48"', $html);
    }

    public function testVisualFigureRenderingIsExplicitAndReceivesTheWholeGroup(): void
    {
        $renderer = new class () implements ContentRenderer {
            public bool $groupReceived = false;
            public function render(GraphicContent|ContentGroup $content): ?ImageContent
            {
                $this->groupReceived = $content instanceof ContentGroup;
                return new ImageContent(null, 'image/png', 72, 36);
            }
        };
        $doc = new TaggedDocument('x.pdf', [new StructureElement('figure', 'Figure', [new TextContent('painted text', 1), new GraphicContent('path', '0 0 m 1 1 l S')], properties: new StructureProperties(alternateDescription: 'chart'))]);
        $html = (new HtmlDeriver(new DerivationPolicy(preferVisualFigures: true), renderer: $renderer))->derive($doc)->html;
        self::assertTrue($renderer->groupReceived);
        self::assertStringNotContainsString('painted text', $html);
        self::assertStringContainsString('alt="chart"', $html);
    }

    public function testFigurePropertiesReachInlineImageWithoutAlt(): void
    {
        $figure = new StructureElement('figure', 'Figure', [new ImageContent(null, 'image/png', 10, 10)], properties: new StructureProperties(language: 'fr'));
        $html = (new HtmlDeriver())->derive(new TaggedDocument('x.pdf', [new StructureElement('p', 'P', [$figure])]))->html;
        self::assertMatchesRegularExpression('/<img[^>]*lang="fr"/', $html);
    }

    public function testAReferenceToSuppressedContentDoesNotProduceABrokenFragment(): void
    {
        $link = new StructureElement('link', 'Link', [new LinkAnnotation(structureTarget: 'private'), new TextContent('Unavailable')]);
        $doc = new TaggedDocument('x.pdf', [$link, new StructureElement('private', 'Private')]);
        $result = (new HtmlDeriver())->derive($doc);
        self::assertStringNotContainsString('href="#', $result->html);
        self::assertContains('unresolved-output-destination', array_column($result->diagnostics, 'code'));
    }

    public function testDuplicateIdsInjectedByFragmentsAreRejected(): void
    {
        $this->expectException(DerivationException::class);
        $source = new StructureElement('p', 'P', associatedFiles: [new AssociatedFile('x.html', 'text/html', 'Alternative', '<span id="same">A</span><span id="same">B</span>')]);
        (new HtmlDeriver())->derive(new TaggedDocument('x.pdf', [$source]));
    }

    public function testRubyBaseRetainsPdfSemanticsWithoutObsoleteHtml(): void
    {
        $source = new StructureElement('ruby', 'Ruby', [new StructureElement('base', 'RB', [new TextContent('漢')]), new StructureElement('text', 'RT', [new TextContent('kan')])]);
        $result = (new HtmlDeriver())->derive(new TaggedDocument('x.pdf', [$source]));
        self::assertStringNotContainsString('<rb', $result->html);
        self::assertStringContainsString('data-pdf-se-type="RB"', $result->html);
        self::assertContains('obsolete-ruby-base', array_column($result->diagnostics, 'code'));
    }
}
