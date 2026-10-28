<?php

declare(strict_types=1);

namespace PDF\Html\Tests;

use PDF\Html\HtmlDeriver;
use PDF\Html\PdfToHtml;
use PDF\Html\Tests\Reading\PdfFixture;
use PDF\Html\Model\AssociatedFile;
use PDF\Html\Model\AttributeObject;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\StructureProperties;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\TextContent;
use PDF\Html\Tests\Support\MathMlCases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Deriving HTML 1.2 Table 4 is adapted to MathML Core 2.1.1 / 4.1, without overriding source CSS. */
final class MathMlLayoutTest extends TestCase
{
    /** @return iterable<string, array{TaggedDocument, string}> */
    public static function nativeCases(): iterable
    {
        foreach ((new MathMlCases())->all() as $name => $case) {
            yield $name => [$case['document'], $case['expectedDisplay']];
        }
    }

    #[DataProvider('nativeCases')]
    public function testDerivedMathKeepsNativeLayout(TaggedDocument $source, string $display): void
    {
        $html = (new HtmlDeriver())->derive($source)->html;
        $dom = $this->dom($html);
        self::assertCount(1, $dom->getElementsByTagName('math'));
        $math = $dom->getElementsByTagName('math')->item(0);
        self::assertInstanceOf(\DOMElement::class, $math);
        self::assertSame($display, $math->hasAttribute('display') ? $math->getAttribute('display') : 'inline');
        foreach (['math', 'msup', 'mfrac'] as $tag) {
            $element = $dom->getElementsByTagName($tag)->item(0);
            self::assertInstanceOf(\DOMElement::class, $element);
            self::assertStringNotContainsString('display:', $element->getAttribute('style'));
            if ($tag !== 'math') {
                self::assertFalse($element->hasAttribute('display'));
            }
        }
        self::assertStringNotContainsString('ignored', $html);
    }

    /** @return iterable<string, array{AttributeObject, string}> */
    public static function explicitStyles(): iterable
    {
        yield 'CSS owner' => [new AttributeObject('CSS-3.00', ['display' => 'inline math']), 'inline math'];
        yield 'HTML style' => [new AttributeObject('HTML-5.00', ['style' => 'display:grid;color:red']), 'grid'];
        yield 'explicit non-math display' => [new AttributeObject('CSS-3.00', ['display' => 'inline']), 'inline'];
    }

    #[DataProvider('explicitStyles')]
    public function testExplicitSourceCssIsNotSilentlyRewritten(AttributeObject $explicit, string $display): void
    {
        $source = (new MathMlCases())->math([
            new AttributeObject('Layout', ['Placement' => 'Block']), $explicit,
        ]);
        $math = $this->dom((new HtmlDeriver())->derive(new TaggedDocument('explicit.pdf', [$source]))->html)->getElementsByTagName('math')->item(0);
        self::assertInstanceOf(\DOMElement::class, $math);
        self::assertStringContainsString('display:' . $display, $math->getAttribute('style'));
        self::assertFalse($math->hasAttribute('display'));
    }

    public function testNativePdfNamespaceAndLayoutReachTheMathMlAdaptation(): void
    {
        foreach (['Inline' => 'inline', 'Block' => 'block'] as $placement => $display) {
            $fixture = new PdfFixture();
            $bytes = $fixture->build([
                4 => $fixture->stream(''),
                7 => '<< /Type /StructElem /S /math /NS 20 0 R /P 6 0 R /A << /O /Layout /Placement /' . $placement . ' >> /K [21 0 R 22 0 R] >>',
                14 => '<< /Nums [] >>',
                20 => '<< /Type /Namespace /NS (http://www.w3.org/1998/Math/MathML) >>',
                21 => '<< /Type /StructElem /S /msup /NS 20 0 R /P 7 0 R /A << /O /Layout /Placement /Inline >> /K [23 0 R 24 0 R] >>',
                22 => '<< /Type /StructElem /S /mfrac /NS 20 0 R /P 7 0 R /A << /O /Layout /Placement /Block >> /K [25 0 R 26 0 R] >>',
                23 => '<< /Type /StructElem /S /mi /NS 20 0 R /P 21 0 R /ActualText (c) >>',
                24 => '<< /Type /StructElem /S /mn /NS 20 0 R /P 21 0 R /ActualText (2) >>',
                25 => '<< /Type /StructElem /S /mn /NS 20 0 R /P 22 0 R /ActualText (1) >>',
                26 => '<< /Type /StructElem /S /mn /NS 20 0 R /P 22 0 R /ActualText (2) >>',
            ]);
            $html = (new PdfToHtml())->convert($bytes, 'native-math.pdf')->html;
            $dom = $this->dom($html);
            $math = $dom->getElementsByTagName('math')->item(0);
            self::assertInstanceOf(\DOMElement::class, $math);
            self::assertSame($display, $math->hasAttribute('display') ? $math->getAttribute('display') : 'inline');
            self::assertSame('c212', $math->textContent);
            foreach (['math', 'msup', 'mfrac'] as $tag) {
                $element = $dom->getElementsByTagName($tag)->item(0);
                self::assertInstanceOf(\DOMElement::class, $element);
                self::assertStringNotContainsString('display:', $element->getAttribute('style'));
            }
        }
    }

    public function testSharedLayoutClassesStillApplyToOrdinaryHtml(): void
    {
        $case = (new MathMlCases())->all()['shared-class-block'];
        $html = (new HtmlDeriver())->derive($case['document'])->html;
        $dom = $this->dom($html);
        $math = $dom->getElementsByTagName('math')->item(0);
        $paragraph = $dom->getElementsByTagName('p')->item(0);
        self::assertInstanceOf(\DOMElement::class, $math);
        self::assertInstanceOf(\DOMElement::class, $paragraph);
        self::assertTrue($math->hasAttribute('data-pdf-math-layout'));
        self::assertFalse($paragraph->hasAttribute('data-pdf-math-layout'));
        self::assertStringContainsString('display:block', $paragraph->getAttribute('style'));
        self::assertStringContainsString(':where(:not([data-pdf-math-layout])){display:block}', $html);
    }

    public function testSharedClassGuardDoesNotRaiseCssSpecificity(): void
    {
        $case = (new MathMlCases())->all()['shared-class-html-precedence'];
        $html = (new HtmlDeriver())->derive($case['document'])->html;
        $span = $this->dom($html)->getElementById('html-class-priority');
        self::assertInstanceOf(\DOMElement::class, $span);
        self::assertSame('spacing explicit', $span->getAttribute('class'));
        self::assertFalse($span->hasAttribute('style'));
        self::assertFalse($span->hasAttribute('data-pdf-math-layout'));
        $spacing = '.spacing:where(:not([data-pdf-math-layout])){display:block}';
        $explicit = '.explicit{display:inline}';
        self::assertStringContainsString($spacing, $html);
        self::assertStringContainsString($explicit, $html);
        self::assertLessThan(strpos($html, $explicit), strpos($html, $spacing));
    }

    public function testExplicitClassCssStillAppliesToMathMl(): void
    {
        $source = (new MathMlCases())->math(properties: new StructureProperties(classes: ['shared']));
        $html = (new HtmlDeriver())->derive(new TaggedDocument('explicit-class.pdf', [$source], classMap: [
            'shared' => [new AttributeObject('Layout', ['Placement' => 'Block']), new AttributeObject('CSS-3.00', ['display' => 'inline math'])],
        ]))->html;
        $math = $this->dom($html)->getElementsByTagName('math')->item(0);
        self::assertInstanceOf(\DOMElement::class, $math);
        self::assertStringContainsString('display:inline math', $math->getAttribute('style'));
        self::assertFalse($math->hasAttribute('display'));
        self::assertStringNotContainsString(':where(:not([data-pdf-math-layout])){display:inline math}', $html);
    }

    public function testAssociatedFragmentKeepsItsOwnDisplayAndStyles(): void
    {
        $fragment = str_replace('<math ', '<math style="display:inline math;color:blue" ', (new MathMlCases())->fragment('inline'));
        $source = new StructureElement('formula', 'Formula', attributes: [
            new AttributeObject('Layout', ['Placement' => 'Block', 'Color' => [1, 0, 0]]),
        ], associatedFiles: [new AssociatedFile('formula.html', 'text/html', 'Alternative', $fragment)]);
        $math = $this->dom((new HtmlDeriver())->derive(new TaggedDocument('fragment.pdf', [$source]))->html)->getElementsByTagName('math')->item(0);
        self::assertInstanceOf(\DOMElement::class, $math);
        self::assertSame('inline', $math->getAttribute('display'));
        self::assertSame('display:inline math;color:blue', $math->getAttribute('style'));
    }

    public function testMathMlClassProtectionRespectsHtmlIntegrationPoints(): void
    {
        $fragment = '<math><mrow class="shared"><mi>x</mi></mrow><mtext><span class="shared">HTML text</span></mtext><semantics><mi>x</mi><annotation-xml encoding="text/html"><div class="shared">HTML annotation</div></annotation-xml></semantics></math>';
        $source = new StructureElement('formula', 'Formula', properties: new StructureProperties(classes: ['shared']), associatedFiles: [
            new AssociatedFile('formula.html', 'text/html', 'Alternative', $fragment),
        ]);
        $document = new TaggedDocument('integration.pdf', [$source], classMap: ['shared' => [new AttributeObject('Layout', ['Placement' => 'Block'])]]);
        $dom = $this->dom((new HtmlDeriver())->derive($document)->html);
        $row = $dom->getElementsByTagName('mrow')->item(0);
        self::assertInstanceOf(\DOMElement::class, $row);
        self::assertTrue($row->hasAttribute('data-pdf-math-layout'));
        foreach (['span', 'div'] as $tag) {
            $element = $dom->getElementsByTagName($tag)->item(0);
            self::assertInstanceOf(\DOMElement::class, $element);
            self::assertFalse($element->hasAttribute('data-pdf-math-layout'));
        }
    }

    public function testFormulaTextFallbackRetainsOrdinaryHtmlLayout(): void
    {
        $source = new StructureElement('formula', 'Formula', [new TextContent('CO2')], attributes: [new AttributeObject('Layout', ['Placement' => 'Inline'])]);
        $html = (new HtmlDeriver())->derive(new TaggedDocument('chemical.pdf', [$source]))->html;
        self::assertStringContainsString('style="display:inline">CO2', $html);
        self::assertStringNotContainsString('<math', $html);
    }

    private function dom(string $html): \DOMDocument
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        return $dom;
    }
}
