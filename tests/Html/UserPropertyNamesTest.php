<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Html;

use PDF\Html\DerivationException;
use PDF\Html\Html\AttributeName;
use PDF\Html\HtmlDeriver;
use PDF\Html\Model\AttributeObject;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\TextContent;
use PHPUnit\Framework\TestCase;

/** Reference 4.3.7.11, physical page 51: replace only invalid name characters. */
final class UserPropertyNamesTest extends TestCase
{
    public function testUnicodeAndAllowedPunctuationSurviveTheWholeOutputBoundary(): void
    {
        $document = new TaggedDocument('properties.pdf', [
            new StructureElement('p', 'P', [new TextContent('Energy')], attributes: [
                new AttributeObject('UserProperties', ['P' => [
                    ['N' => 'Énergie', 'V' => 0, 'F' => '0 kWh', 'H' => false],
                    ['N' => 'énergie', 'V' => 1],
                    ['N' => '温度+°C', 'V' => 20],
                    ['N' => 'Unit price', 'V' => 2],
                ]]),
            ]),
        ]);
        $html = (new HtmlDeriver())->derive($document)->html;

        self::assertStringContainsString('data-pdf-up-Énergie-v="0"', $html);
        self::assertStringContainsString('data-pdf-up-Énergie-f="0 kWh"', $html);
        self::assertStringContainsString('data-pdf-up-Énergie-h="false"', $html);
        self::assertStringContainsString('data-pdf-up-énergie-v="1"', $html);
        self::assertStringContainsString('data-pdf-up-温度+°c-v="20"', $html);
        self::assertStringContainsString('data-pdf-up-unit_price-v="2"', $html);
    }

    public function testForbiddenSyntaxControlsAndNoncharactersBecomeUnderscores(): void
    {
        $names = new AttributeName();
        self::assertSame('a________b', $names->userProperty("A \"'/=>\x00\u{1FFFF}B"));
        self::assertTrue($names->valid('data-pdf-up-温度+°c-v'));
        self::assertFalse($names->valid('data-pdf-up-bad name-v'));
        self::assertFalse($names->valid("data-pdf-up-\u{FDD0}-v"));
        self::assertFalse($names->valid(''));
    }

    public function testInvalidUtf8IsRejectedInsteadOfLosingThePropertyName(): void
    {
        $this->expectException(DerivationException::class);
        (new AttributeName())->userProperty("invalid\xff");
    }
}
