<?php

declare(strict_types=1);

namespace PDF\Html\Tests;

use PDF\Html\DerivationException;
use PDF\Html\DerivationLimits;
use PDF\Html\DerivationPolicy;
use PDF\Html\HtmlDeriver;
use PDF\Html\Model\AssociatedFile;
use PDF\Html\Model\AttributeObject;
use PDF\Html\Model\AuthenticatedPermissions;
use PDF\Html\Model\ImageContent;
use PDF\Html\Model\LinkAnnotation;
use PDF\Html\Model\MarkedContent;
use PDF\Html\Model\RoleMapping;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\StructureProperties;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\TextContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtmlDeriverTest extends TestCase
{
    public function testDocumentMetadataTextEscapingAndPageNavigation(): void
    {
        $document = new TaggedDocument('fallback.pdf', [new StructureElement('p', 'P', [new TextContent('été <script>&', 2)])], 'fr', 'Un titre & sûr', pageLabels: [2 => 'II']);
        $result = (new HtmlDeriver())->derive($document);
        self::assertStringStartsWith("<!DOCTYPE html>\n<html lang=\"fr\">", $result->html);
        self::assertStringContainsString('<title>Un titre &amp; sûr</title>', $result->html);
        self::assertStringContainsString('été &lt;script&gt;&amp;', $result->html);
        self::assertStringContainsString('id="PDF-Page-2"', $result->html);
        self::assertStringContainsString('href="#PDF-Page-2">II</a>', $result->html);
        self::assertStringContainsString('<meta charset="UTF-8">', $result->html);
        self::assertStringContainsString('width=device-width, initial-scale=1', $result->html);
    }

    public function testDepthFirstOrderAndTransitiveRoleMapping(): void
    {
        $source = new StructureElement('custom', 'MyParagraph', [new TextContent('first'), new StructureElement('span', 'Span', [new TextContent('second')]), new TextContent('third')]);
        $document = new TaggedDocument('x.pdf', [$source], roleMappings: [new RoleMapping('MyParagraph', null, 'Custom'), new RoleMapping('Custom', null, 'P')]);
        $html = (new HtmlDeriver())->derive($document)->html;
        self::assertStringContainsString('data-pdf-se-type="P" data-pdf-se-type-original="MyParagraph Custom"', $html);
        self::assertMatchesRegularExpression('/first.*second.*third/', $html);
    }

    public function testNamespaceObjectIdentityKeepsDistinctRoleMaps(): void
    {
        $document = new TaggedDocument('x.pdf', [new StructureElement('a', 'Thing', [new TextContent('A')], 'urn:custom', namespaceIdentity: 'ns-a'), new StructureElement('b', 'Thing', [new TextContent('B')], 'urn:custom', namespaceIdentity: 'ns-b')], roleMappings: [new RoleMapping('Thing', 'urn:custom', 'P', 'http://iso.org/pdf2/ssn', 'ns-a'), new RoleMapping('Thing', 'urn:custom', 'H2', 'http://iso.org/pdf2/ssn', 'ns-b')]);
        $html = (new HtmlDeriver())->derive($document)->html;
        self::assertStringContainsString('data-pdf-se-type="P"', $html);
        self::assertStringContainsString('<h2', $html);
    }

    public function testRoleCyclesFailExplicitly(): void
    {
        $this->expectException(DerivationException::class);
        (new HtmlDeriver())->derive(new TaggedDocument('x.pdf', [new StructureElement('a', 'A')], roleMappings: [new RoleMapping('A', null, 'B'), new RoleMapping('B', null, 'A')]));
    }

    public function testActualTextIncludingEmptySuppressesChildrenAndExpansionWins(): void
    {
        $doc = new TaggedDocument('x.pdf', [new StructureElement('empty', 'P', [new StructureElement('unknown', 'Unsupported')], properties: new StructureProperties(actualText: '')), new StructureElement('abbr', 'Figure', [new TextContent('ignored')], properties: new StructureProperties(actualText: 'Dr.', alternateDescription: 'drawing', expansion: 'Doctor'))]);
        $html = (new HtmlDeriver())->derive($doc)->html;
        self::assertStringNotContainsString('Unsupported', $html);
        self::assertStringNotContainsString('ignored', $html);
        self::assertStringContainsString('title="Doctor">Dr.</abbr>', $html);
    }

    public function testClassPropertiesAndDirectAttributesRespectOwnerPrecedence(): void
    {
        $p = new StructureElement('p', 'P', [new TextContent('X')], properties: new StructureProperties(classes: ['Heading Style']), attributes: [new AttributeObject('Layout', ['Color' => [0, 0, 1]]), new AttributeObject('CSS-3.00', ['color' => 'green'])]);
        $result = (new HtmlDeriver())->derive(new TaggedDocument('x.pdf', [$p], classMap: ['Heading Style' => [new AttributeObject('Layout', ['Color' => [1, 0, 0]]), new AttributeObject('HTML-5.00', ['title' => 'class title'])]]));
        self::assertStringContainsString('title="class title"', $result->html);
        self::assertStringContainsString('color:green', $result->html);
        self::assertStringContainsString('.pdf-class-' . bin2hex('Heading Style'), $result->css);
    }

    public function testListsSplitAndReopenParagraphAncestorsWithoutDuplicateIds(): void
    {
        $list = new StructureElement('l', 'L', [new StructureElement('item', 'LI', [new TextContent('middle')])]);
        $root = new StructureElement('root', 'Div', [new StructureElement('p', 'P', [new StructureElement('sub', 'Sub', [new TextContent('before'), $list, new TextContent('after')])], properties: new StructureProperties(id: 'paragraph'))]);
        $html = (new HtmlDeriver())->derive(new TaggedDocument('x.pdf', [$root]))->html;
        self::assertMatchesRegularExpression('~before</span></p><ul.*middle.*</ul><p.*after</span></p>~', $html);
        self::assertSame(1, substr_count($html, 'id="paragraph"'));
    }

    public function testDescriptionAndNestedListsKeepExplicitLabels(): void
    {
        $list = new StructureElement('l', 'L', [new StructureElement('item', 'LI', [new StructureElement('label', 'Lbl', [new TextContent('Term')]), new StructureElement('body', 'LBody', [new TextContent('Definition')])])], attributes: [new AttributeObject('List', ['ListNumbering' => 'Description'])]);
        $html = (new HtmlDeriver())->derive(new TaggedDocument('x.pdf', [$list]))->html;
        self::assertStringContainsString('<dl', $html);
        self::assertStringContainsString('<dt', $html);
        self::assertStringContainsString('<dd', $html);
        $nested = new StructureElement('outer', 'L', [$list]);
        self::assertStringContainsString('<li style="list-style-type:none"><dl', (new HtmlDeriver())->derive(new TaggedDocument('x.pdf', [$nested]))->html);
    }

    public function testCaptionsBecomeFirstAndNestedTableIsLifted(): void
    {
        $table = new StructureElement('table', 'Table', [new StructureElement('row', 'TR', [new StructureElement('th', 'TH', [new StructureElement('h', 'H1', [new TextContent('header')])])]), new StructureElement('caption', 'Caption', [new TextContent('caption'), new StructureElement('inner', 'Table')])]);
        $html = (new HtmlDeriver())->derive(new TaggedDocument('x.pdf', [$table]))->html;
        self::assertMatchesRegularExpression('~<table[^>]*><caption.*caption</caption><tr~', $html);
        self::assertMatchesRegularExpression('~header</p></th></tr></table><table~', $html);
        self::assertStringNotContainsString('<h1', $html);
    }

    public function testInlineFigurePropagatesAlternateDescriptionThroughLink(): void
    {
        $figure = new StructureElement('figure', 'Figure', [new StructureElement('link', 'Link', [new LinkAnnotation(uri: 'https://example.org/'), new ImageContent(null, 'image/png', 72, 36, 1)])], properties: new StructureProperties(alternateDescription: 'A star'));
        $result = (new HtmlDeriver())->derive(new TaggedDocument('x.pdf', [new StructureElement('p', 'P', [$figure])]));
        self::assertStringNotContainsString('<figure', $result->html);
        self::assertStringContainsString('alt="A star"', $result->html);
        self::assertStringContainsString('width="96" height="48"', $result->html);
        self::assertContains('image-placeholder', array_column($result->diagnostics, 'code'));
    }

    public function testReferenceContainingLinkProducesOneAnchorAndCorrectDestination(): void
    {
        $reference = new StructureElement('reference', 'Reference', [new StructureElement('link', 'Link', [new LinkAnnotation(structureTarget: 'target'), new TextContent('see target')])]);
        $target = new StructureElement('target', 'P', [new TextContent('target')], properties: new StructureProperties(id: 'chapter'));
        $html = (new HtmlDeriver())->derive(new TaggedDocument('x.pdf', [$reference, $target]))->html;
        self::assertSame(1, substr_count($html, '<a '));
        self::assertStringContainsString('href="#chapter"', $html);
    }

    public function testMarkedContentHasOneLanguageWrapperAndReplacementExpansion(): void
    {
        $marked = new MarkedContent([new TextContent('ignored')], new StructureProperties(language: 'el', actualText: 'lambda', alternateDescription: 'letter', expansion: 'wavelength'), 1);
        $html = (new HtmlDeriver())->derive(new TaggedDocument('x.pdf', [new StructureElement('p', 'P', [$marked])]))->html;
        self::assertStringContainsString('<span lang="el" role="img" aria-label="letter"><abbr title="wavelength">lambda</abbr></span>', $html);
        self::assertStringNotContainsString('ignored', $html);
    }

    public function testAlternativeSkipsUnsupportedChildrenAndSupplementKeepsCaption(): void
    {
        $alternative = new StructureElement('alt', 'Figure', [new StructureElement('unsupported', 'Unmapped')], associatedFiles: [new AssociatedFile('x.html', 'text/html', 'Alternative', '<p>replacement</p>')]);
        $html = (new HtmlDeriver())->derive(new TaggedDocument('x.pdf', [$alternative]))->html;
        self::assertStringContainsString('replacement</p>', $html);
        self::assertStringNotContainsString('<figure', $html);
        $supplement = new StructureElement('supp', 'Figure', [new StructureElement('caption', 'Caption', [new TextContent('caption')]), new TextContent('ignored')], associatedFiles: [new AssociatedFile('x.html', 'text/html', 'Supplement', '<span>supplement</span>')]);
        $html = (new HtmlDeriver())->derive(new TaggedDocument('x.pdf', [$supplement]))->html;
        self::assertStringContainsString('caption</figcaption>', $html);
        self::assertStringContainsString('supplement</span>', $html);
        self::assertStringNotContainsString('ignored', $html);
    }

    public function testSafeProfileBlocksActiveAndObfuscatedOutput(): void
    {
        $p = new StructureElement('p', 'P', [new TextContent('safe')], attributes: [new AttributeObject('HTML-5.00', ['onclick' => 'alert(1)', 'title' => '"><script>']), new AttributeObject('CSS-3.00', ['background' => 'url(https://example.org/tracker)', 'color' => 'red'])]);
        $link = new StructureElement('link', 'Link', [new LinkAnnotation(uri: "java\nscript:alert(1)"), new TextContent('link')]);
        $doc = new TaggedDocument('x.pdf', [$p, $link], associatedFiles: [new AssociatedFile('head.html', 'text/html', 'Supplement', '<script>alert(1)</script><iframe src="https://example.org/"></iframe>')], documentScripts: ['app.alert(1)']);
        $result = (new HtmlDeriver())->derive($doc);
        self::assertStringNotContainsString('<script', $result->html);
        self::assertStringNotContainsString('<iframe', $result->html);
        self::assertStringNotContainsString('onclick=', $result->html);
        self::assertStringNotContainsString('background:', $result->html);
        self::assertStringNotContainsString('href="java', $result->html);
        self::assertContains('scripts-disabled', array_column($result->diagnostics, 'code'));
    }

    public function testEncryptedPermissionContextAndSessionReuse(): void
    {
        $deriver = new HtmlDeriver();
        $doc = new TaggedDocument('x.pdf', [new StructureElement('p', 'P', [new TextContent('first', 1)])], permissions: new AuthenticatedPermissions(-44, true));
        self::assertStringContainsString('data-pdf-perms="-44"', $deriver->derive($doc)->html);
        $second = $deriver->derive(new TaggedDocument('second.pdf', [new StructureElement('p', 'P', [new TextContent('second')])]));
        self::assertStringNotContainsString('PDF-Page-1', $second->html);
        self::assertStringNotContainsString('data-pdf-perms', $second->html);
    }

    #[DataProvider('limits')]
    public function testLimitsAreEnforced(DerivationLimits $limits): void
    {
        $this->expectException(DerivationException::class);
        (new HtmlDeriver(limits: $limits))->derive(new TaggedDocument('x.pdf', [new StructureElement('a', 'Div', [new StructureElement('b', 'P', [new TextContent(str_repeat('X', 100))])])]));
    }

    /** @return iterable<string, array{DerivationLimits}> */
    public static function limits(): iterable
    {
        yield 'nodes' => [new DerivationLimits(maximumNodes: 2)];
        yield 'depth' => [new DerivationLimits(maximumDepth: 1)];
        yield 'text' => [new DerivationLimits(maximumOutputBytes: 50)];
    }
}
