<?php

declare(strict_types=1);

namespace PDF\Html\Tests;

use PDF\Html\DerivationException;
use PDF\Html\DerivationLimits;
use PDF\Html\DerivationPolicy;
use PDF\Html\Html\ContentModelRepair;
use PDF\Html\Html\Element;
use PDF\Html\Html\SafetyFilter;
use PDF\Html\Html\Serializer;
use PDF\Html\Html\Text;
use PHPUnit\Framework\TestCase;

final class HtmlBoundaryTest extends TestCase
{
    public function testHeadFragmentsRetainMetadataButNeverRefreshOrUnsafeEncoding(): void
    {
        foreach ([false,true] as $trusted) {
            $filter = new SafetyFilter(new DerivationPolicy(allowActiveContent:$trusted), new DerivationLimits());
            $nodes = $filter->fragment('<meta name="description" content="Résumé"><title>Document title</title><meta HTTP-EQUIV="Refresh" content="0;url=https://evil.test/"><meta charset="UTF-7"><script>alert(1)</script>', head:true);
            $tags = array_map(static fn ($node): string => $node->tag, $nodes);
            self::assertSame($trusted ? ['meta','title','script'] : ['meta','title'], $tags);
            self::assertSame('Résumé', $nodes[0]->attributes['content']);
            self::assertSame('Document title', $nodes[1]->children[0]->value);
            self::assertNotEmpty($filter->diagnostics);
        }
    }

    public function testHeadResourcesAndStylesRespectPolicies(): void
    {
        $safe = new SafetyFilter(new DerivationPolicy(), new DerivationLimits());
        self::assertSame([], $safe->fragment('<link rel="stylesheet" href="https://example.test/a.css"><style>p{color:red}</style>', head:true));
        $trusted = new SafetyFilter(new DerivationPolicy(allowActiveContent:true, allowRemoteResources:true), new DerivationLimits());
        $nodes = $trusted->fragment('<link rel="stylesheet" href="https://example.test/a.css"><style>p{color:red;background:url(https://evil.test/a)}</style>', head:true);
        self::assertCount(2, $nodes);
        self::assertSame('https://example.test/a.css', $nodes[0]->attributes['href']);
        self::assertSame('p{color:red}', $nodes[1]->children[0]->value);
    }

    public function testGeneratedHeadStylesSurviveSafeOutputFiltering(): void
    {
        $filter = new SafetyFilter(new DerivationPolicy(), new DerivationLimits());
        $head = new Element('head', [], [new Element('style', [], [new Text('.paragraph{color:blue}')])]);
        $filter->filter($head);
        self::assertSame('style', $head->children[0]->tag);
        self::assertSame('.paragraph{color:blue}', $head->children[0]->children[0]->value);
        self::assertSame([], $filter->fragment('<style>.paragraph{color:red}</style>', head: true));
    }

    public function testBodyStylesheetCapabilitiesDoNotAdmitImportsOrOtherResourceRelations(): void
    {
        foreach ([new DerivationPolicy(allowEmbeddedStylesheets: true), new DerivationPolicy(allowRemoteResources: true)] as $policy) {
            $filter = new SafetyFilter($policy, new DerivationLimits());
            foreach (['import', 'preload', 'prefetch', 'icon', 'alternate', 'stylesheet import', ''] as $relation) {
                $link = new Element('link', ['rel' => $relation, 'href' => 'local.css']);
                $body = new Element('body', [], [$link]);
                $filter->filter($body);
                self::assertSame([], $body->children, $relation);
                self::assertSame([], $filter->fragment('<link rel="'.$relation.'" href="local.css">'), $relation);
            }
            $nodes = $filter->fragment('<link rel="stylesheet" href="local.css">');
            self::assertCount(1, $nodes);
            self::assertSame('local.css', $nodes[0]->attributes['href']);
        }
    }

    public function testBodyStylesheetsStillRequireAnExplicitCapabilityAndSafeResourceUrl(): void
    {
        $blocked = new SafetyFilter(new DerivationPolicy(), new DerivationLimits());
        self::assertSame([], $blocked->fragment('<link rel="stylesheet" href="local.css">'));
        $local = new SafetyFilter(new DerivationPolicy(allowEmbeddedStylesheets: true), new DerivationLimits());
        foreach (['https://evil.test/a.css', '//evil.test/a.css', '/a.css', '../a.css', 'a%2e%2e.css', 'javascript:alert(1)', 'data:text/css,p{color:red}'] as $url) {
            self::assertSame([], $local->fragment('<link rel="stylesheet" href="'.$url.'">'), $url);
            $body = new Element('body', [], [new Element('link', ['rel' => 'stylesheet', 'href' => $url])]);
            $local->filter($body);
            self::assertSame([], $body->children, $url);
        }
        $remote = new SafetyFilter(new DerivationPolicy(allowRemoteResources: true), new DerivationLimits());
        $nodes = $remote->fragment('<link rel="stylesheet" href="https://example.test/a.css">');
        self::assertCount(1, $nodes);
        self::assertSame('https://example.test/a.css', $nodes[0]->attributes['href']);
    }

    public function testStylesheetCommentsCannotHideUrlFunctions(): void
    {
        $filter = new SafetyFilter(new DerivationPolicy(allowActiveContent: true), new DerivationLimits());
        self::assertSame('p{color:red}', $filter->stylesheet('/* comment */ p{color:red;background:u/**/rl(https://evil.test/x)}'));
        self::assertSame('', $filter->stylesheet('/* unterminated'));
    }

    public function testFragmentBytesAreCumulativeAcrossCalls(): void
    {
        $filter = new SafetyFilter(new DerivationPolicy(), new DerivationLimits(maximumOutputBytes:20));
        $filter->fragment('<p>123</p>');
        $filter->fragment('<p>456</p>');
        $this->expectException(DerivationException::class);
        $filter->fragment('x');
    }

    public function testFragmentTokenLimitRunsBeforeDomAllocation(): void
    {
        $filter = new SafetyFilter(new DerivationPolicy(), new DerivationLimits(maximumNodes:1));
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage('preparse');
        $filter->fragment(str_repeat('<br>', 9));
    }

    public function testFragmentCannotDeclareExternalEntities(): void
    {
        $filter = new SafetyFilter(new DerivationPolicy(allowActiveContent:true), new DerivationLimits());
        $this->expectException(DerivationException::class);
        $filter->fragment('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///private.txt">]><math>&e;</math>', math:true);
    }

    public function testNestedAnchorsAreSplitAndBothDestinationsRemain(): void
    {
        $root = new Element('p', [], [new Element('a', ['id' => 'outer','href' => '#outer-target'], [
            new Text('before'),new Element('span', [], [new Text('middle'),new Element('a', ['href' => '#inner-target'], [new Text('inner')]),new Text('tail')]),new Text('after'),
        ])]);
        $nodes = (new ContentModelRepair())->repair($root);
        $html = (new Serializer())->serialize($nodes[0]);
        self::assertSame(1, substr_count($html, 'id="outer"'));
        self::assertStringContainsString('href="#inner-target"', $html);
        self::assertGreaterThanOrEqual(2, substr_count($html, 'href="#outer-target"'));
        $xpath = $this->xpath($html);
        self::assertSame(0, $xpath->query('//a//a')->length);
        self::assertSame('beforemiddleinnertailafter', $xpath->query('//body')->item(0)->textContent);
    }

    public function testInvalidOuterLabelRetainsInnerLabelAndAllControls(): void
    {
        $root = new Element('div', [], [new Element('label', ['for' => 'one'], [
            new Text('Outer'),new Element('span', [], [new Element('label', ['for' => 'two'], [new Text('Inner')])]),
            new Element('input', ['id' => 'one']),new Element('input', ['id' => 'two']),
        ])]);
        $repair = new ContentModelRepair();
        $html = (new Serializer())->serialize($repair->repair($root)[0]);
        $xpath = $this->xpath($html);
        self::assertSame(0, $xpath->query('//label//label')->length);
        self::assertSame(1, $xpath->query('//label[@for="two"]')->length);
        self::assertSame(2, $xpath->query('//input')->length);
        self::assertNotEmpty($repair->diagnostics);
    }

    public function testOneImplicitLabeledControlIsPreserved(): void
    {
        $root = new Element('label', [], [new Text('Name'),new Element('span', [], [new Element('input', ['id' => 'name'])])]);
        $nodes = (new ContentModelRepair())->repair($root);
        self::assertSame('label', $nodes[0]->tag);
        self::assertSame('span', $nodes[0]->children[1]->tag);
    }

    public function testFigureCaptionMovesToAnEndAndAdditionalCaptionBecomesDiv(): void
    {
        $root = new Element('figure', [], [new Element('img', ['src' => 'image.png','alt' => 'Image']),new Element('figcaption', [], [new Text('First')]),new Element('p', [], [new Text('Body')]),new Element('figcaption', [], [new Text('Second')])]);
        $nodes = (new ContentModelRepair())->repair($root);
        self::assertSame('figcaption', $nodes[0]->children[0]->tag);
        self::assertSame('div', $nodes[0]->children[3]->tag);
        $xpath = $this->xpath((new Serializer())->serialize($nodes[0]));
        self::assertSame(1, $xpath->query('//figure/figcaption')->length);
    }

    public function testTableCaptionAndNestedListsRemainOutsideTableCaption(): void
    {
        $root = new Element('div', [], [new Element('table', [], [
            new Element('tr', [], [new Element('td', [], [new Text('Cell')])]),
            new Element('caption', [], [new Text('Caption'),new Element('ul', [], [new Element('li', [], [new Text('List')])])]),
        ])]);
        $nodes = (new ContentModelRepair())->repair($root);
        self::assertSame('caption', $nodes[0]->children[0]->children[0]->tag);
        self::assertSame('ul', $nodes[0]->children[1]->tag);
        $xpath = $this->xpath((new Serializer())->serialize($nodes[0]));
        self::assertSame(0, $xpath->query('//caption//ul | //caption//table')->length);
    }

    public function testListCaptionMovesOutAndNestedListGetsAnArtificialItem(): void
    {
        $root = new Element('div', [], [new Element('ol', [], [
            new Element('div', ['data-pdf-se-type' => 'Caption'], [new Text('Items')]),
            new Element('ul', [], [new Element('li', [], [new Text('Nested')])]),
        ])]);
        $nodes = (new ContentModelRepair())->repair($root);
        self::assertSame('div', $nodes[0]->children[0]->tag);
        self::assertSame('ol', $nodes[0]->children[1]->tag);
        self::assertSame('li', $nodes[0]->children[1]->children[0]->tag);
        self::assertSame('ul', $nodes[0]->children[1]->children[0]->children[0]->tag);
    }

    public function testHeadingsAndSectioningInsideHeaderCellsAreRepairedAtAnyDepth(): void
    {
        $root = new Element('table', [], [new Element('tr', [], [new Element('th', [], [
            new Element('div', [], [new Element('section', [], [new Element('h2', [], [new Text('Header')])])]),
        ])])]);
        $nodes = (new ContentModelRepair())->repair($root);
        $xpath = $this->xpath((new Serializer())->serialize($nodes[0]));
        self::assertSame(0, $xpath->query('//th//h2 | //th//section')->length);
        self::assertSame(1, $xpath->query('//th//p')->length);
    }

    public function testRubyBaseAndContainerAreAdaptedToCurrentHtml(): void
    {
        $root = new Element('ruby', [], [new Element('rb', [], [new Text('Base')]),new Element('rtc', [], [new Element('rt', [], [new Text('Annotation')])])]);
        $repair = new ContentModelRepair();
        $nodes = $repair->repair($root);
        $html = (new Serializer())->serialize($nodes[0]);
        self::assertStringNotContainsString('<rb', $html);
        self::assertStringNotContainsString('<rtc', $html);
        self::assertStringContainsString('Base', $html);
        self::assertSame('rt', $nodes[0]->children[1]->tag);
        self::assertNotEmpty($repair->diagnostics);
    }

    public function testRepairNodeBudgetIncludesNewSplitObjects(): void
    {
        $root = new Element('div', [], [new Element('span', [], [
            new Text('a'),new Element('ul', [], [new Element('li', [], [new Text('x')])]),new Text('b'),
        ])]);
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage('node budget');
        (new ContentModelRepair(new DerivationLimits(maximumNodes:1)))->repair($root);
    }

    public function testRepairByteBudgetIncludesRepeatedAttributesInSplits(): void
    {
        $root = new Element('span', ['class' => str_repeat('x', 100)], [new Text('a'),new Element('div', [], [new Text('x')]),new Text('b')]);
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage('byte budget');
        (new ContentModelRepair(new DerivationLimits(maximumOutputBytes:250)))->repair($root);
    }

    public function testRepairRejectsCycles(): void
    {
        $root = new Element('div');
        $root->children[] = $root;
        $this->expectException(DerivationException::class);
        (new ContentModelRepair())->repair($root);
    }

    public function testSerializerBudgetIncludesHtmlEntityExpansion(): void
    {
        $this->expectException(DerivationException::class);
        (new Serializer(new DerivationLimits(maximumOutputBytes:20)))->serialize(new Text('&&&&&'));
    }

    public function testSerializerBudgetIsCumulativeAcrossSiblings(): void
    {
        $root = new Element('p', [], [new Text(str_repeat('a', 10)),new Text(str_repeat('b', 10))]);
        $this->expectException(DerivationException::class);
        (new Serializer(new DerivationLimits(maximumOutputBytes:25)))->serialize($root);
    }

    public function testRawEndTagSplitAcrossTextNodesCannotEscapeScript(): void
    {
        $root = new Element('script', [], [new Text('var x="</scr'),new Text('ipt><img src=x onerror=alert(1)>";')]);
        $html = (new Serializer())->serialize($root);
        self::assertSame(1, substr_count(strtolower($html), '</script'));
        self::assertStringContainsString('<\\/script>', $html);
        $xpath = $this->xpath($html);
        self::assertSame(0, $xpath->query('//img')->length);
        self::assertSame(1, $xpath->query('//script')->length);
    }

    public function testDuplicateHeadTitleCharsetAndViewportAreNotEmitted(): void
    {
        $root = new Element('head', [], [
            new Element('title', [], [new Text('Original')]),new Element('title', [], [new Text('Extra')]),
            new Element('meta', ['charset' => 'UTF-8']),new Element('meta', ['charset' => 'UTF-8']),
            new Element('meta', ['name' => 'viewport','content' => 'width=device-width']),new Element('meta', ['name' => 'viewport','content' => 'width=100']),
        ]);
        $nodes = (new ContentModelRepair())->repair($root);
        self::assertCount(3, $nodes[0]->children);
    }

    public function testContentAndElementaryMathMlVocabularyAndAttributesArePreserved(): void
    {
        $filter = new SafetyFilter(new DerivationPolicy(), new DerivationLimits());
        $nodes = $filter->fragment('<math xmlns="http://www.w3.org/1998/Math/MathML" intent="sum($a,$b)" arg="formula"><semantics><mstack stackalign="right" charalign="."><mscarries position="2"><mscarry crossout="updiagonalstrike"><mn>1</mn></mscarry></mscarries><msrow><mn>42</mn></msrow><msline length="2"/></mstack><annotation-xml encoding="application/mathml-content+xml"><apply><plus/><ci type="real">a</ci><cn type="integer" base="10">2</cn></apply><csymbol cd="arith1" definitionURL="https://example.test/symbol">plus</csymbol></annotation-xml></semantics></math>', math: true);
        self::assertCount(1, $nodes);
        $html = (new Serializer())->serialize($nodes[0]);
        foreach (['mstack','mscarries','mscarry','msline','annotation-xml','apply','plus','ci','cn','csymbol'] as $tag) {
            self::assertStringContainsString('<'.$tag, $html);
        }
        self::assertStringContainsString('crossout="updiagonalstrike"', $html);
        self::assertStringContainsString('definitionurl="https://example.test/symbol"', $html);
        self::assertStringContainsString('base="10"', $html);
        self::assertStringContainsString('intent="sum($a,$b)"', $html);
        self::assertContains('extended-mathml-vocabulary', array_map(static fn ($diagnostic): string => $diagnostic->code, $filter->diagnostics));
    }

    public function testActiveHtmlNamesAreNeverMathMlEvenUnderTrustedPolicy(): void
    {
        $filter = new SafetyFilter(new DerivationPolicy(allowActiveContent: true), new DerivationLimits());
        $math = 'http://www.w3.org/1998/Math/MathML';
        foreach (['script','style','link','iframe','img','button','foreignObject'] as $name) {
            self::assertFalse($filter->tagAllowed($name, $math));
        }
        self::assertTrue($filter->tagAllowed('apply', $math));
        self::assertFalse($filter->tagAllowed('apply', 'http://www.w3.org/1999/xhtml'));
        self::assertFalse($filter->tagAllowed('ci', 'https://example.test/fake'));
    }

    public function testHtmlAnnotationUsesHtmlFilteringAndCannotInjectActiveMarkup(): void
    {
        $filter = new SafetyFilter(new DerivationPolicy(), new DerivationLimits());
        $nodes = $filter->fragment('<math xmlns="http://www.w3.org/1998/Math/MathML"><semantics><mi>x</mi><annotation-xml encoding="application/xhtml+xml"><span xmlns="http://www.w3.org/1999/xhtml" onclick="attack()">Description<img src="javascript:attack()" onerror="attack()"/><script>attack()</script></span></annotation-xml></semantics></math>', math: true);
        $html = (new Serializer())->serialize($nodes[0]);
        self::assertStringContainsString('<annotation-xml', $html);
        self::assertStringContainsString('Description', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('onerror', $html);
        self::assertStringNotContainsString('javascript:', $html);
    }

    public function testMathNamespaceSpoofingCannotEnableTrustedHtmlScripts(): void
    {
        $filter = new SafetyFilter(new DerivationPolicy(allowActiveContent: true), new DerivationLimits());
        $nodes = $filter->fragment('<math xmlns="http://www.w3.org/1998/Math/MathML"><semantics><mi>x</mi><annotation-xml encoding="text/html"><script>fakeMathScript()</script><script xmlns="http://www.w3.org/1999/xhtml">explicitTrustedHtml()</script></annotation-xml></semantics></math>', math: true);
        $html = (new Serializer())->serialize($nodes[0]);
        self::assertStringNotContainsString('fakeMathScript', $html);
        self::assertStringContainsString('explicitTrustedHtml()', $html);
        self::assertSame(1, substr_count($html, '<script'));
    }

    public function testForeignNamespacesCannotSmuggleHtmlIntoMath(): void
    {
        $filter = new SafetyFilter(new DerivationPolicy(allowActiveContent: true), new DerivationLimits());
        $nodes = $filter->fragment('<math xmlns="http://www.w3.org/1998/Math/MathML"><mrow xmlns="https://example.test/fake"><mi>fake</mi></mrow><script xmlns="http://www.w3.org/1999/xhtml">outsideIntegration()</script><mi>safe</mi></math>', math: true);
        $html = (new Serializer())->serialize($nodes[0]);
        self::assertStringNotContainsString('fake', $html);
        self::assertStringNotContainsString('outsideIntegration', $html);
        self::assertStringContainsString('safe', $html);
    }

    public function testMathMlUrlsAndEventsUseTheSameSecurityBoundary(): void
    {
        $filter = new SafetyFilter(new DerivationPolicy(), new DerivationLimits());
        $nodes = $filter->fragment('<math xmlns="http://www.w3.org/1998/Math/MathML"><mi href="javascript:attack()" onclick="attack()" mathcolor="url(https://evil.test/color)">x</mi><mglyph src="https://evil.test/glyph.png" alt="glyph"/><csymbol definitionURL="javascript:attack()" cd="arith1">symbol</csymbol></math>', math: true);
        $html = (new Serializer())->serialize($nodes[0]);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('evil.test', $html);
        self::assertStringContainsString('alt="glyph"', $html);
        self::assertStringContainsString('cd="arith1"', $html);
    }

    public function testMathTextIntegrationPointCanContainSafeHtmlFields(): void
    {
        $filter = new SafetyFilter(new DerivationPolicy(), new DerivationLimits());
        $nodes = $filter->fragment('<math xmlns="http://www.w3.org/1998/Math/MathML"><mtext>Value <input xmlns="http://www.w3.org/1999/xhtml" type="text" name="answer" onchange="attack()"/><mglyph src="javascript:attack()" alt="?"/></mtext></math>', math: true);
        $html = (new Serializer())->serialize($nodes[0]);
        self::assertStringContainsString('<input type="text" name="answer">', $html);
        self::assertStringNotContainsString('onchange', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('<mglyph alt="?">', $html);
    }

    public function testMathNamesOutsideMathCannotBecomeUnknownHtmlElements(): void
    {
        $filter = new SafetyFilter(new DerivationPolicy(), new DerivationLimits());
        $body = new Element('body', [], [new Element('apply', [], [new Element('ci', [], [new Text('x')])])]);
        $filter->filter($body);
        self::assertSame([], $body->children);
    }

    public function testExplicitFakeMathFilterNamespaceRejectsHtmlRoot(): void
    {
        $filter = new SafetyFilter(new DerivationPolicy(allowActiveContent: true), new DerivationLimits());
        $this->expectException(DerivationException::class);
        $filter->filter(new Element('script', [], [new Text('attack()')]), namespace: 'http://www.w3.org/1998/Math/MathML');
    }

    public function testXmlNamespaceResetCannotMasqueradeAsTrustedHtml(): void
    {
        $filter = new SafetyFilter(new DerivationPolicy(allowActiveContent: true), new DerivationLimits());
        $nodes = $filter->fragment('<math xmlns="http://www.w3.org/1998/Math/MathML"><semantics><mi>x</mi><annotation-xml encoding="text/html"><script xmlns="">noNamespaceFake()</script><script xmlns="http://www.w3.org/1999/xhtml">declaredHtml()</script></annotation-xml></semantics></math>', math: true);
        $html = (new Serializer())->serialize($nodes[0]);
        self::assertStringNotContainsString('noNamespaceFake', $html);
        self::assertStringContainsString('declaredHtml()', $html);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $old = libxml_use_internal_errors(true);
        try {
            self::assertTrue($document->loadHTML('<!DOCTYPE html><html><head><title>Test</title></head><body>'.$html.'</body></html>', LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
        return new \DOMXPath($document);
    }
}
