<?php

declare(strict_types=1);

namespace PDF\Html\Tests;

use PDF\Html\HtmlDeriver;
use PDF\Html\Model\ContentGroup;
use PDF\Html\Model\ImageContent;
use PDF\Html\Model\LinkAnnotation;
use PDF\Html\Model\MarkedContent;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\TextContent;
use PDF\Html\Model\Widget;
use PDF\Html\Reading\ContentInstructionReader;
use PDF\Html\Reading\ReadingBudget;
use PDF\Html\Reading\ReadingException;
use PDF\Html\Reading\ReadingLimits;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PHPUnit\Framework\TestCase;

final class ReadingSourceTest extends TestCase
{
    public function testIndependentPdf20StructureContentAndMetadata(): void
    {
        $document = (new ReadPdfSource(policy: new \PDF\Html\DerivationPolicy(allowActiveContent: true)))->read($this->document(), 'report.pdf');
        self::assertSame('fr-FR', $document->language);
        self::assertSame('Independent tagged source', $document->title);
        self::assertSame([1 => 'Intro-i'], $document->pageLabels);
        self::assertTrue($document->hasAcroForm);
        self::assertCount(1, $document->associatedFiles);
        self::assertSame('text/css', $document->associatedFiles[0]->mediaType);
        self::assertSame('.lead { color: #123456; }', $document->associatedFiles[0]->bytes);
        self::assertSame(['var sourceOnly = true;'], $document->documentScripts);
        self::assertSame(['O' => 'console.println("page");'], $document->pageScripts[1]);
        $elements = $document->structure[0]->children;
        self::assertCount(6, $elements);
        self::assertInstanceOf(StructureElement::class, $elements[0]);
        self::assertSame('CustomHeading', $elements[0]->type);
        self::assertSame('heading', $elements[0]->properties->id);
        self::assertSame('https://example.test/custom', $elements[1]->namespace);
        self::assertNotNull($elements[1]->namespaceIdentity);
        self::assertSame(['lead'], $elements[1]->properties->classes);
        self::assertSame('Center', $elements[1]->attributes[0]->values['TextAlign']);
        self::assertInstanceOf(MarkedContent::class, $elements[1]->children[0]);
        self::assertSame('Texte remplaçant', $elements[1]->children[0]->properties->actualText);
        self::assertSame('fr', $elements[1]->children[0]->properties->language);
        $formContent = $elements[2]->children[0];
        self::assertInstanceOf(MarkedContent::class, $formContent);
        self::assertInstanceOf(TextContent::class, $formContent->children[0]);
        self::assertSame('éABCfi', $formContent->children[0]->text);
        $image = $elements[3]->children[0]->children[0];
        self::assertInstanceOf(ImageContent::class, $image);
        self::assertSame('image/png', $image->mediaType);
        self::assertSame(20.0, $image->widthPoints);
        self::assertSame(20.0, $image->heightPoints);
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $image->bytes);
        $link = $elements[4]->children[1];
        self::assertInstanceOf(LinkAnnotation::class, $link);
        self::assertSame(1, $link->pageTarget);
        $widget = $elements[5]->children[0];
        self::assertInstanceOf(Widget::class, $widget);
        self::assertSame('Person.Name', $widget->field->name);
        self::assertSame('Ada', $widget->field->value);
        self::assertSame(2, $widget->field->flags);
        self::assertSame(50, $widget->field->maximumLength);
        self::assertSame(['V' => 'event.rc = true;'], $widget->field->actions);
    }

    public function testDerivedHtmlUsesTheActualExternalPdf(): void
    {
        $source = (new ReadPdfSource())->read($this->document(), 'report.pdf');
        $result = (new HtmlDeriver())->derive($source);
        self::assertStringContainsString('External tagged PDF', $result->html);
        self::assertStringContainsString('Texte remplaçant', $result->html);
        self::assertStringContainsString('éABCfi', $result->html);
        self::assertStringNotContainsString('visual text', $result->html);
        self::assertStringContainsString('width="27" height="27"', $result->html);
    }

    public function testAdaptationBudgetsAreCumulativeAndPerCall(): void
    {
        $adapter = new ReadPdfSource(new ReadingLimits(maximumTextBytes: 8));
        $this->expectException(ReadingException::class);
        $adapter->read($this->document(), 'report.pdf');
    }

    public function testContentOperatorsInsideLiteralStringsAreNotExecuted(): void
    {
        $reader = new ContentInstructionReader(new ReadingBudget(new ReadingLimits()));
        $instructions = iterator_to_array($reader->read('(EMC /Fake Do) Tj'));
        self::assertCount(1, $instructions);
        self::assertSame('Tj', $instructions[0]->operator);
        self::assertSame('EMC /Fake Do', $instructions[0]->operands[0]->value);
    }

    public function testInlineImageAmbiguityIsExplicitlyRejected(): void
    {
        $this->expectException(ReadingException::class);
        iterator_to_array((new ContentInstructionReader(new ReadingBudget(new ReadingLimits())))->read('BI /W 1 /H 1 ID ambiguous EI bytes EI'));
    }

    private function document(): \PDF\Reading\ReadDocument
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/tagged.pdf');
        self::assertNotFalse($bytes);
        return (new DocumentReader())->read(new FileBytes($bytes));
    }
}
