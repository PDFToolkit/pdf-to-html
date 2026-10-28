<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Attributes;

use PDF\Html\Attributes\AttributeDeriver;
use PDF\Html\Model\AttributeObject;
use PHPUnit\Framework\TestCase;

final class AttributeDeriverTest extends TestCase
{
    public function testPrioritiesUnitsAndWritingModes(): void
    {
        $result = (new AttributeDeriver())->derive([
            new AttributeObject('CSS-3.00', ['color' => 'red','list-style-type' => 'lower-greek']),
            new AttributeObject('Layout', ['Color' => [0,0,1],'TextAlign' => 'Justify','Padding' => [1,2,3,4],'TextPosition' => 'Sup','WritingMode' => 'RlTb']),
            new AttributeObject('List', ['ListNumbering' => 'UpperRoman']),
            new AttributeObject('ARIA-1.1', ['role' => 'heading']),
            new AttributeObject('HTML-5.00', ['role' => 'presentation','disabled' => false]),
        ]);
        self::assertSame('red', $result->css['color']);
        self::assertSame('lower-greek', $result->css['list-style-type']);
        self::assertSame('justify', $result->css['text-align']);
        self::assertSame('1.333333px 5.333333px 2.666667px 4px', $result->css['padding']);
        self::assertSame('rtl', $result->css['direction']);
        self::assertSame('heading', $result->html['role']);
        self::assertSame('sup', $result->textPosition);
        self::assertArrayNotHasKey('disabled', $result->html);
    }
    public function testNamespaceIdentityRatherThanUriOnly(): void
    {
        $uri = 'http://www.w3.org/1999/xhtml';
        $attribute = new AttributeObject('NSO', ['title' => 'matched'], $uri, '17:0');
        $deriver = new AttributeDeriver();
        self::assertSame(['title' => 'matched'], $deriver->derive([$attribute], $uri, '17:0')->html);
        self::assertSame([], $deriver->derive([$attribute], $uri, '19:0')->html);
        self::assertSame([], $deriver->derive([$attribute], $uri)->html);
    }
    public function testBothScopeKeepsItsSourceMeaningWithoutAnInvalidOrStaleHtmlScope(): void
    {
        $deriver = new AttributeDeriver();
        $both = $deriver->derive([new AttributeObject('Table', ['Scope' => 'Row']), new AttributeObject('Table', ['Scope' => 'Both'])]);
        self::assertSame('Both', $both->html['data-pdf-table-scope']);
        self::assertArrayNotHasKey('scope', $both->html);
        $column = $deriver->derive([new AttributeObject('Table', ['Scope' => 'Both']), new AttributeObject('Table', ['Scope' => 'Column'])]);
        self::assertSame('col', $column->html['scope']);
        self::assertArrayNotHasKey('data-pdf-table-scope', $column->html);
    }

    public function testTableAttributesDoNotBecomeCssAndBothScopeHasADiagnostic(): void
    {
        $deriver = new AttributeDeriver();
        $result = $deriver->derive([new AttributeObject('Table', ['ColSpan' => 2,'RowSpan' => 3,'Headers' => ['a','b'],'Scope' => 'Column','Short' => 'Amount','TBorderStyle' => 'Dotted','TPadding' => 6])]);
        self::assertSame(['colspan' => '2','rowspan' => '3','headers' => 'a b','scope' => 'col','abbr' => 'Amount'], $result->html);
        self::assertSame(['border-style' => 'dotted','padding' => '8px'], $result->css);
        $both = $deriver->derive([new AttributeObject('Table', ['Scope' => 'Both'])]);
        self::assertArrayNotHasKey('scope', $both->html);
        self::assertNotEmpty($both->diagnostics);
    }
    public function testUserPropertiesPreserveZeroAndFalse(): void
    {
        $result = (new AttributeDeriver())->derive([new AttributeObject('UserProperties', ['P' => [['N' => 'Unit price','V' => 0,'F' => '0.00','H' => false]]])]);
        self::assertSame(['data-pdf-up-unit_price-v' => '0','data-pdf-up-unit_price-f' => '0.00','data-pdf-up-unit_price-h' => 'false'], $result->html);
    }
    public function testRubyAndDecorationPropertiesAreDistinctFromTextAlignment(): void
    {
        $result = (new AttributeDeriver())->derive([new AttributeObject('Layout', [
            'TextAlign' => 'Justify','RubyAlign' => 'Justify','RubyPosition' => 'Before',
            'TextDecorationType' => 'LineThrough','TextDecorationThickness' => 1,'SpaceBefore' => 12,
        ])]);
        self::assertSame('justify', $result->css['text-align']);
        self::assertSame('space-around', $result->css['ruby-align']);
        self::assertSame('over', $result->css['ruby-position']);
        self::assertSame('line-through', $result->css['text-decoration']);
        self::assertSame('16px', $result->css['margin-top']);
    }
    public function testUnsupportedOwnerAndContinuationAreNeverSilentlyProjected(): void
    {
        $result = (new AttributeDeriver())->derive([
            new AttributeObject('List', ['ListNumbering' => 'Description','ContinuedList' => true]),
            new AttributeObject('html-5.0', ['onclick' => 'danger()']),
            new AttributeObject('PrintField', ['Role' => 'cb','Checked' => 'on']),
        ]);
        self::assertTrue($result->printField);
        self::assertSame('Description', $result->listNumbering);
        self::assertArrayNotHasKey('onclick', $result->html);
        self::assertCount(2, $result->diagnostics);
    }
}
