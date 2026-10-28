<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Reading;

use PDF\Html\Reading\DocumentMetadataReader;
use PDF\Html\Reading\ReadingBudget;
use PDF\Html\Reading\ReadingException;
use PDF\Html\Reading\ReadingLimits;
use PDF\Html\Reading\SourceValues;
use PDF\Reading\DocumentReader;
use PHPUnit\Framework\TestCase;

final class ScriptActionReaderTest extends TestCase
{
    public function testNextArraysPreserveOrderAndSharedActions(): void
    {
        $fixture = new PdfFixture();
        self::assertSame("first();\n;\nsecond();\n;\nthird();\n;\nsecond();", $this->read([
            15 => '<< /S /JavaScript /JS (first\(\);) /Next [16 0 R 17 0 R 16 0 R] >>',
            16 => '<< /S /JavaScript /JS (second\(\);) >>',
            17 => '<< /S /JavaScript /JS 18 0 R >>',
            18 => $fixture->stream('third();'),
        ]));
    }

    public function testCyclesAreRejectedBeforeExport(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('script action chain contains a cycle');
        $this->read([15 => '<< /S /JavaScript /JS (first) /Next 15 0 R >>']);
    }

    public function testMalformedPayloadIsNotSilentlyOmitted(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('text string or stream');
        $this->read([15 => '<< /S /JavaScript /JS 42 >>']);
    }

    public function testAChainCannotResetTheCumulativeTextBudget(): void
    {
        $this->expectException(ReadingException::class);
        $this->read([
            15 => '<< /S /JavaScript /JS (123456) /Next 16 0 R >>',
            16 => '<< /S /JavaScript /JS (123456) >>',
        ], new ReadingLimits(maximumTextBytes: 10));
    }

    /** @param array<int, string> $objects */
    private function read(array $objects, ReadingLimits $limits = new ReadingLimits()): ?string
    {
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /OpenAction 15 0 R >>';
        $document = (new DocumentReader())->read((new PdfFixture())->build($objects));
        return (new DocumentMetadataReader(new SourceValues($document, new ReadingBudget($limits))))->script($document->catalog()->get('OpenAction'));
    }
}
