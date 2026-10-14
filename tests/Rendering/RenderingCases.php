<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Rendering;

use PDF\Html\Tests\Reading\PdfFixture;
use PDF\Reading\FileBytes;

/** Independently authored PDF syntax, with deliberate paints outside the selected structure. */
final readonly class RenderingCases
{
    /** @return array<string, FileBytes> */
    public function documents(): array
    {
        $fixture = new PdfFixture();
        $base = [
            8 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding /FirstChar 32 /LastChar 126 /Widths ['.implode(' ', array_fill(0, 95, '500')).'] /FontDescriptor 12 0 R >>',
            12 => '<< /Type /FontDescriptor /FontName /Helvetica /Flags 32 /FontBBox [-166 -225 1000 931] /ItalicAngle 0 /Ascent 718 /Descent -207 /CapHeight 718 /StemV 88 >>',
            6 => '<< /Type /StructElem /S /Document /P 5 0 R /K [7 0 R 10 0 R] >>',
            7 => '<< /Type /StructElem /S /Figure /P 6 0 R /Pg 3 0 R /K 0 >>',
            10 => '<< /Type /StructElem /S /Figure /P 6 0 R /Pg 3 0 R /K 1 >>',
            14 => '<< /Nums [0 [7 0 R 10 0 R]] >>',
        ];
        $documents = [];
        $documents['clipped-path'] = $fixture->build($base + [4 => $fixture->stream('/Figure <</MCID 1>> BDC 1 1 0 rg 0 0 200 200 re f EMC q 1 0 0 1 20 20 cm 0 0 40 40 re W n 1 0 0 rg /Figure <</MCID 0>> BDC 0 0 100 100 re f EMC Q')]);
        $documents['text-position'] = $fixture->build($base + [4 => $fixture->stream('BT /F1 20 Tf 20 100 Td /Figure <</MCID 1>> BDC (AAA) Tj EMC /Figure <</MCID 0>> BDC (B) Tj EMC ET')]);
        $documents['inherited-form'] = $fixture->build([
            3 => '<< /Type /Page /Parent 2 0 R /StructParents 0 /MediaBox [0 0 200 200] /Resources << /Font << /F1 8 0 R >> /XObject << /Fm 9 0 R >> >> /Contents 4 0 R >>',
            4 => $fixture->stream('q 1 0 0 1 20 20 cm 0 0 40 40 re W n 1 0 0 rg /Fm Do Q'),
            6 => '<< /Type /StructElem /S /Document /P 5 0 R /K [7 0 R 10 0 R] >>',
            7 => '<< /Type /StructElem /S /Figure /P 6 0 R /Pg 3 0 R /K << /Type /MCR /Pg 3 0 R /Stm 9 0 R /MCID 0 >> >>',
            9 => $fixture->stream('/Figure <</MCID 0>> BDC 0 0 100 100 re f EMC /Figure <</MCID 1>> BDC 0 1 0 rg 0 0 100 100 re f EMC', '/Type /XObject /Subtype /Form /BBox [0 0 100 100] /Matrix [1 0 0 1 5 5] /Resources << >> /StructParents 1'),
            10 => '<< /Type /StructElem /S /Figure /P 6 0 R /Pg 3 0 R /K << /Type /MCR /Pg 3 0 R /Stm 9 0 R /MCID 1 >> >>',
            14 => '<< /Nums [1 [7 0 R 10 0 R]] >>',
        ] + $base);
        $documents['rotation-unit'] = $fixture->build($base + [
            3 => '<< /Type /Page /Parent 2 0 R /StructParents 0 /MediaBox [0 0 200 200] /CropBox [0 0 100 80] /Rotate 90 /UserUnit 2 /Resources << >> /Contents 4 0 R >>',
            4 => $fixture->stream('/Figure <</MCID 0>> BDC 1 0 0 rg 10 10 20 20 re f EMC /Figure <</MCID 1>> BDC 0 1 0 rg 50 50 20 20 re f EMC'),
        ]);
        return $documents;
    }
}
