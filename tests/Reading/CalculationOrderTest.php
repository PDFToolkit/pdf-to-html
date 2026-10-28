<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Reading;

use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Reading\ReadingException;
use PDF\Reading\DocumentReader;
use PHPUnit\Framework\TestCase;

final class CalculationOrderTest extends TestCase
{
    public function testSourceOrderPreservesQualifiedFieldNames(): void
    {
        self::assertSame(['Group.Second', 'First'], $this->read('[10 0 R 9 0 R]'));
    }

    public function testInvalidEntriesAreRejected(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('calculation-order entry');
        $this->read('[10 0 R (First)]');
    }

    public function testDuplicateNamesAreRejected(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('unique fully qualified names');
        $this->read('[9 0 R 9 0 R]');
    }

    public function testNonArrayIsRejected(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('calculation order must be an array');
        $this->read('(First)');
    }

    /** @return list<string> */
    private function read(string $order): array
    {
        $source = (new DocumentReader())->read((new PdfFixture())->build([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /AcroForm << /Fields [9 0 R 11 0 R] /CO '.$order.' >> >>',
            9 => '<< /T (First) /FT /Tx >>',
            10 => '<< /T (Second) /Parent 11 0 R /FT /Tx >>',
            11 => '<< /T (Group) /Kids [10 0 R] >>',
        ]));
        return (new ReadPdfSource())->read($source, 'order.pdf')->calculationOrder;
    }
}
