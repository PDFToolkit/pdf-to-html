<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Rendering;

use PDF\Html\Tests\Reading\PdfFixture;
use PDF\Reading\FileBytes;

/** Independent syntax targeting inheritance, artifacts and resource-graph depth. */
final readonly class RenderingReviewCases
{
    /** @return array<string, FileBytes> */
    public function documents(): array
    {
        $fixture = new PdfFixture();
        $base = [
            8 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding /FirstChar 32 /LastChar 126 /Widths [' . implode(' ', array_fill(0, 95, '500')) . '] /FontDescriptor 12 0 R >>',
            12 => '<< /Type /FontDescriptor /FontName /Helvetica /Flags 32 /FontBBox [-166 -225 1000 931] /ItalicAngle 0 /Ascent 718 /Descent -207 /CapHeight 718 /StemV 88 >>',
            6 => '<< /Type /StructElem /S /Document /P 5 0 R /K [7 0 R 10 0 R] >>',
            7 => '<< /Type /StructElem /S /Figure /P 6 0 R /Pg 3 0 R /K 0 >>',
            10 => '<< /Type /StructElem /S /Figure /P 6 0 R /Pg 3 0 R /K 1 >>',
            14 => '<< /Nums [0 [7 0 R 10 0 R]] >>',
        ];
        $formObjects = [
            3 => '<< /Type /Page /Parent 2 0 R /StructParents 0 /MediaBox [0 0 200 200] /Resources << /Font << /F1 8 0 R >> /XObject << /Fm 9 0 R >> >> /Contents 4 0 R >>',
            4 => $fixture->stream('BT 7 Tr ET /Fm Do'),
            7 => '<< /Type /StructElem /S /Figure /P 6 0 R /Pg 3 0 R /K << /Type /MCR /Pg 3 0 R /Stm 9 0 R /MCID 0 >> >>',
            9 => $fixture->stream('BT /F1 40 Tf 20 100 Td /Figure <</MCID 1>> BDC (B) Tj EMC ET /Figure <</MCID 0>> BDC 1 0 0 rg 0 0 200 200 re f EMC', '/Type /XObject /Subtype /Form /BBox [0 0 200 200] /Resources << /Font << /F1 8 0 R >> >> /StructParents 1'),
            10 => '<< /Type /StructElem /S /Figure /P 6 0 R /Pg 3 0 R /K << /Type /MCR /Pg 3 0 R /Stm 9 0 R /MCID 1 >> >>',
            14 => '<< /Nums [1 [7 0 R 10 0 R]] >>',
        ] + $base;
        $form = $fixture->build($formObjects);
        $artifact = $fixture->build($base + [4 => $fixture->stream('/Figure <</MCID 0>> BDC /Artifact BMC 1 1 0 rg 0 0 200 200 re f EMC 1 0 0 rg 50 50 20 20 re f EMC /Figure <</MCID 1>> BDC EMC')]);
        $chain = [
            3 => '<< /Type /Page /Parent 2 0 R /StructParents 0 /MediaBox [0 0 200 200] /Resources << /Properties << /Deep 20 0 R >> >> /Contents 4 0 R >>',
            4 => $fixture->stream('/Figure <</MCID 0>> BDC 1 0 0 rg 50 50 20 20 re f EMC /Figure <</MCID 1>> BDC EMC'),
            25 => '<< /PrivateKey 1 >>',
        ];
        for ($object = 20; $object < 25; ++$object) {
            $chain[$object] = '<< /PrivateKey ' . ($object + 1) . ' 0 R >>';
        }
        $standardFont = $fixture->build([
            8 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => $fixture->stream('/Figure <</MCID 0>> BDC BT /F1 20 Tf 20 100 Td (B) Tj ET EMC /Figure <</MCID 1>> BDC EMC'),
        ] + $base);
        return [
            'inherited-text-clip' => $form,
            'nested-artifact' => $artifact,
            'deep-resource' => $fixture->build($chain + $base),
            'pdf17-standard-font' => new FileBytes(str_replace('%PDF-2.0', '%PDF-1.7', $standardFont->bytes)),
            'form-null-external-file' => $fixture->build(array_replace($formObjects, [9 => str_replace('/StructParents 1', '/StructParents 1 /F null', $formObjects[9])])),
            'page-transparency-group' => $fixture->build([
                3 => '<< /Type /Page /Parent 2 0 R /StructParents 0 /MediaBox [0 0 200 200] /Group << /Type /Group /S /Transparency /CS /DeviceCMYK /I true /K false >> /Resources << /ExtGState << /Alpha << /ca 0.5 >> >> >> /Contents 4 0 R >>',
                4 => $fixture->stream('q /Alpha gs /Figure <</MCID 0>> BDC 1 0 0 0 k 0 0 200 200 re f EMC Q /Figure <</MCID 1>> BDC EMC'),
            ] + $base),
            'hidden-optional-content' => $fixture->build([
                1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /OCProperties << /OCGs [20 0 R] /D << /Name (Default) /BaseState /ON /OFF [20 0 R] /Order [20 0 R] >> >> >>',
                3 => '<< /Type /Page /Parent 2 0 R /StructParents 0 /MediaBox [0 0 200 200] /Resources << /Properties << /Hidden 20 0 R >> >> /Contents 4 0 R >>',
                4 => $fixture->stream('/Figure <</MCID 0>> BDC /OC /Hidden BDC 1 0 0 rg 0 0 200 200 re f EMC 0 0 1 rg 50 50 20 20 re f EMC /Figure <</MCID 1>> BDC EMC'),
                20 => '<< /Type /OCG /Name (Hidden layer) >>',
            ] + $base),
            'null-page-context' => $fixture->build([
                1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /OCProperties null >>',
                3 => '<< /Type /Page /Parent 2 0 R /StructParents 0 /MediaBox [0 0 200 200] /Group null /Resources << >> /Contents 4 0 R >>',
                4 => $fixture->stream('/Figure <</MCID 0>> BDC 1 0 0 rg 50 50 20 20 re f EMC /Figure <</MCID 1>> BDC EMC'),
            ] + $base),
            'indirect-catalog-version' => new FileBytes(str_replace('%PDF-2.0', '%PDF-1.7', $fixture->build([
                1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /Version 20 0 R >>',
                4 => $fixture->stream('/Figure <</MCID 0>> BDC 1 0 0 rg 50 50 20 20 re f EMC /Figure <</MCID 1>> BDC EMC'),
                20 => '/2.0',
            ] + $base)->bytes)),
        ];
    }
}
