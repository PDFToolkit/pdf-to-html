<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Attributes;

use PDF\Html\Attributes\AttributeDeriver;
use PDF\Html\HtmlDeriver;
use PDF\Html\Model\AttributeObject;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\StructureProperties;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\TextContent;
use PHPUnit\Framework\TestCase;

/** Deriving HTML 1.2, 4.3.7.3/9: CSS property keys retain their CSS semantics. */
final class CssAttributeSemanticsTest extends TestCase
{
    public function testCustomPropertiesRemainDistinctThroughOwnerPrecedence(): void
    {
        $attributes = (new AttributeDeriver())->derive([
            new AttributeObject('CSS-3.00', ['--Accent' => 'green', 'COLOR' => 'var(--Accent)']),
            new AttributeObject('HTML-5.00', ['style' => '--Accent:red;--accent:blue']),
        ]);

        self::assertSame('green', $attributes->css['--Accent']);
        self::assertSame('blue', $attributes->css['--accent']);
        self::assertSame('var(--Accent)', $attributes->css['color']);
    }

    public function testCustomPropertiesSurviveClassMapAndOutputFiltering(): void
    {
        $result = (new HtmlDeriver())->derive(new TaggedDocument('custom-properties.pdf', [
            new StructureElement('p', 'P', [new TextContent('Text')], properties: new StructureProperties(classes: ['theme'])),
        ], classMap: [
            'theme' => [new AttributeObject('CSS-3.00', ['--Accent' => 'red', '--accent' => 'blue', 'color' => 'var(--Accent)'])],
        ]));

        self::assertStringContainsString('--Accent:red;--accent:blue;color:var(--Accent)', $result->css);
        self::assertStringContainsString('--Accent:red;--accent:blue;color:var(--Accent)', $result->html);
    }

    public function testNamespaceStyleDoesNotRenameCustomProperties(): void
    {
        $namespace = 'http://www.w3.org/1999/xhtml';
        $attributes = (new AttributeDeriver())->derive([
            new AttributeObject('NSO', ['style' => '--Tone:orange;color:var(--Tone)'], $namespace, 'html'),
        ], $namespace, 'html');

        self::assertSame('orange', $attributes->css['--Tone']);
        self::assertArrayNotHasKey('--tone', $attributes->css);
        self::assertSame('var(--Tone)', $attributes->css['color']);
    }
}
