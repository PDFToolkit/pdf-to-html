<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Reading;

use PDF\Reading\FileBytes;

/** Independent hand-authored PDF syntax; never calls the PDFToolkit writer. */
final readonly class PdfFixture
{
    /** @param array<int, string|null> $overrides */
    public function build(array $overrides = []): FileBytes
    {
        $objects = array_replace([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /StructParents 0 /MediaBox [0 0 200 200] /Resources << /Font << /F1 8 0 R >> >> /Contents 4 0 R >>',
            4 => $this->stream('/P <</MCID 0>> BDC BT /F1 12 Tf (Visible) Tj ET EMC'),
            5 => '<< /Type /StructTreeRoot /K 6 0 R /ParentTree 14 0 R /ParentTreeNextKey 1 >>',
            6 => '<< /Type /StructElem /S /Document /P 5 0 R /K 7 0 R >>',
            7 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 3 0 R /K 0 >>',
            8 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            14 => '<< /Nums [0 [7 0 R]] >>',
        ], $overrides);
        $objects = array_filter($objects, static fn (?string $object): bool => $object !== null);
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
        return new FileBytes($bytes . 'trailer << /Size ' . $size . ' /Root 1 0 R >>' . "\nstartxref\n" . $xref . "\n%%EOF\n");
    }

    public function stream(string $bytes, string $entries = ''): string
    {
        return '<< /Length ' . strlen($bytes) . ' ' . $entries . ">>\nstream\n" . $bytes . "\nendstream";
    }
}
