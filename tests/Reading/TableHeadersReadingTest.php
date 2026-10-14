<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Reading;

use PDF\Html\HtmlDeriver;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Reading\ReadingException;
use PDF\Html\Reading\StructureValidationMode;
use PDF\Reading\DocumentReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TableHeadersReadingTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function identifiers(): iterable
    {
        yield 'binary non-text byte' => ['ff00', 'pdf-id-ff00'];
        yield 'space is encoded' => ['612062', 'pdf-id-612062'];
        yield 'reserved prefix cannot collide' => [bin2hex('pdf-id-ff00'), 'pdf-id-' . bin2hex('pdf-id-ff00')];
        yield 'ordinary ASCII remains intact' => [bin2hex('header'), 'header'];
    }

    #[DataProvider('identifiers')]
    public function testHeaderReferencesUseExactlyTheSameByteIdentityAsIds(string $hex, string $expected): void
    {
        $html = $this->html($hex);
        self::assertStringContainsString('id="' . $expected . '"', $html);
        self::assertStringContainsString('headers="' . $expected . '"', $html);
        $document = new \DOMDocument();
        self::assertTrue($document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING));
        $xpath = new \DOMXPath($document);
        self::assertSame(1, $xpath->query('//th[@id="' . $expected . '"]')->length);
        self::assertSame(1, $xpath->query('//td[@headers="' . $expected . '"]')->length);
    }

    public function testMalformedHeaderArrayIsRejected(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('Headers must be an array');
        $this->html('ff00', '(not-an-array)');
    }

    private function html(string $id, ?string $headers = null): string
    {
        $fixture = new PdfFixture();
        $headers ??= '[<' . $id . '>]';
        $source = (new ReadPdfSource(structureValidation: StructureValidationMode::Strict))->read((new DocumentReader())->read($fixture->build([
            4 => $fixture->stream('/TH <</MCID 0>> BDC BT /F1 12 Tf (Header) Tj ET EMC /TD <</MCID 1>> BDC BT /F1 12 Tf (Cell) Tj ET EMC'),
            7 => '<< /Type /StructElem /S /Table /P 6 0 R /Pg 3 0 R /K 10 0 R >>',
            10 => '<< /Type /StructElem /S /TR /P 7 0 R /K [11 0 R 12 0 R] >>',
            11 => '<< /Type /StructElem /S /TH /P 10 0 R /ID <' . $id . '> /K 0 >>',
            12 => '<< /Type /StructElem /S /TD /P 10 0 R /K 1 /A << /O /Table /Headers ' . $headers . ' >> >>',
            14 => '<< /Nums [0 [11 0 R 12 0 R]] >>',
        ])), 'headers.pdf');
        return (new HtmlDeriver())->derive($source)->html;
    }
}
