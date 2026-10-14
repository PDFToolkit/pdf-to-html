<?php

declare(strict_types=1);

namespace PDF\Html\Tests;

use PDF\Html\PdfToHtml;
use PDF\Html\Reading\ReadingException;
use PDF\Reading\FileBytes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The source adapter must not decode glyphs which the reference replaces. */
final class ReadingReplacementTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function replacements(): iterable
    {
        yield 'nonempty' => ['Replacement'];
        yield 'empty is a replacement' => [''];
    }

    #[DataProvider('replacements')]
    public function testStructureReplacementPrecedesSharedPageTextDecoding(string $replacement): void
    {
        $html = $this->convert([
            6 => '<< /Type /StructElem /S /Document /P 5 0 R /K [7 0 R 10 0 R] >>',
            7 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 3 0 R /K 0 /ActualText (' . $replacement . ') >>',
            10 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 3 0 R /K 1 >>',
            4 => $this->stream('/P <</MCID 1>> BDC BT /F1 12 Tf (Visible) Tj ET EMC /P <</MCID 0>> BDC BT /F2 12 Tf (S) Tj ET EMC'),
        ]);
        self::assertStringContainsString('Visible', $html);
        self::assertStringContainsString('PDF-Page-1', $html);
        self::assertStringNotContainsString('unmapped-text', $html);
        if ($replacement !== '') {
            self::assertStringContainsString($replacement, $html);
        }
    }

    public function testStructureReplacementSuppressesDescendantMcrStreamAndObjectAppearance(): void
    {
        $html = $this->convert([
            7 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 3 0 R /K [10 0 R << /Type /OBJR /Obj 11 0 R >>] /ActualText (Whole subtree) >>',
            10 => '<< /Type /StructElem /S /Span /P 7 0 R /K << /Type /MCR /MCID 2 /Stm 12 0 R >> >>',
            11 => $this->stream('BT /F2 12 Tf (S) Tj ET', '/Type /XObject /Subtype /Form'),
            12 => $this->stream('/P <</MCID 2>> BDC BT /F2 12 Tf (S) Tj ET EMC', '/Type /XObject /Subtype /Form'),
        ]);
        self::assertStringContainsString('Whole subtree', $html);
        self::assertStringContainsString('PDF-Page-1', $html);
    }

    public function testNestedMarkedReplacementSuppressesDescendantGlyphDecoding(): void
    {
        $html = $this->convert([
            4 => $this->stream('/P <</MCID 0>> BDC /Span <</ActualText (Replacement)>> BDC /Span <</Lang (fr)>> BDC BT /F2 12 Tf (S) Tj ET EMC EMC EMC'),
        ]);
        self::assertStringContainsString('Replacement', $html);
        self::assertStringNotContainsString('unmapped-text', $html);
    }

    public function testUnreplacedTextStillRejectsIncoherentUnicodeCodespaces(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('outside its ToUnicode codespace');
        $this->convert([4 => $this->stream('/P <</MCID 0>> BDC BT /F2 12 Tf (S) Tj ET EMC')]);
    }

    public function testActualTextDoesNotHideInvalidStructureParents(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('inconsistent parent');
        $this->convert([7 => '<< /Type /StructElem /S /P /P 5 0 R /Pg 3 0 R /K 0 /ActualText (Replacement) >>']);
    }

    /** @param array<int, string> $overrides */
    private function convert(array $overrides): string
    {
        $objects = array_replace([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << /Font << /F1 8 0 R /F2 9 0 R >> >> /Contents 4 0 R >>',
            4 => $this->stream('/P <</MCID 0>> BDC BT /F1 12 Tf (Visible) Tj ET EMC'),
            5 => '<< /Type /StructTreeRoot /K 6 0 R >>',
            6 => '<< /Type /StructElem /S /Document /P 5 0 R /K 7 0 R >>',
            7 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 3 0 R /K 0 >>',
            8 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            9 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /ToUnicode 13 0 R >>',
            13 => $this->stream('1 begincodespacerange <0000> <ffff> endcodespacerange 1 beginbfchar <53> <0053> endbfchar'),
        ], $overrides);
        ksort($objects);
        $bytes = "%PDF-2.0\n";
        $offsets = [];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($bytes);
            $bytes .= $number . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($bytes);
        $size = max(array_keys($objects)) + 1;
        $bytes .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
        for ($number = 1; $number < $size; ++$number) {
            $bytes .= isset($offsets[$number]) ? sprintf("%010d 00000 n \n", $offsets[$number]) : "0000000000 00000 f \n";
        }
        $bytes .= 'trailer << /Size ' . $size . ' /Root 1 0 R >>' . "\nstartxref\n" . $xref . "\n%%EOF\n";
        return (new PdfToHtml())->convert(new FileBytes($bytes), 'replacement.pdf')->html;
    }

    private function stream(string $bytes, string $entries = ''): string
    {
        return '<< /Length ' . strlen($bytes) . ' ' . $entries . ">>\nstream\n" . $bytes . "\nendstream";
    }
}
