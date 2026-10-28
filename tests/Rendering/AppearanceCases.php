<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Rendering;

use PDF\Html\Tests\Reading\PdfFixture;
use PDF\Reading\FileBytes;

/** Independently authored appearance streams: transformed bounds, resources and three distinct states. */
final readonly class AppearanceCases
{
    /** @param array<int, string|null> $overrides */
    public function document(array $overrides = []): FileBytes
    {
        $fixture = new PdfFixture();
        $form = '/Type /XObject /Subtype /Form /BBox [0 0 30 15] /Matrix [2 0 0 2 7 9] /Resources << /Font << /F1 8 0 R >> >>';
        return $fixture->build(array_replace([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /AcroForm << /Fields [9 0 R] >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << >> /Contents 4 0 R /Annots [9 0 R] >>',
            4 => $fixture->stream(''),
            7 => '<< /Type /StructElem /S /Form /P 6 0 R /Pg 3 0 R /K << /Type /OBJR /Obj 9 0 R >> >>',
            8 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding /FirstChar 32 /LastChar 126 /Widths [' . implode(' ', array_fill(0, 95, '500')) . '] /FontDescriptor 15 0 R >>',
            15 => '<< /Type /FontDescriptor /FontName /Helvetica /Flags 32 /FontBBox [-166 -225 1000 931] /ItalicAngle 0 /Ascent 718 /Descent -207 /CapHeight 718 /StemV 88 >>',
            9 => '<< /Type /Annot /Subtype /Widget /StructParent 0 /P 3 0 R /Rect [10 20 70 50] /FT /Btn /Ff 65536 /T (Action) /H /P /AS /On /AP << /N << /Off 13 0 R /On 10 0 R >> /D << /On 11 0 R >> /R << /On 12 0 R >> >> /MK << /CA (Caption) >> >>',
            10 => $fixture->stream('1 0 0 rg -10 -10 50 35 re f 0 g BT /F1 6 Tf 2 3 Td (Normal) Tj ET', $form),
            11 => $fixture->stream('0 0 1 rg -10 -10 50 35 re f 0 g BT /F1 6 Tf 2 3 Td (Down) Tj ET', $form),
            12 => $fixture->stream('0 1 0 rg -10 -10 50 35 re f 0 g BT /F1 6 Tf 2 3 Td (Rollover) Tj ET', $form),
            13 => $fixture->stream('0 g 0 0 30 15 re f', $form),
            14 => '<< /Nums [0 7 0 R] >>',
        ], $overrides));
    }
}
