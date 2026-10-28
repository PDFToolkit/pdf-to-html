<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Reading;

use PDF\Html\DerivationPolicy;
use PDF\Html\HtmlDeriver;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Reading\StructureValidationMode;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\UnsupportedPdfFeature;
use PHPUnit\Framework\TestCase;

/** Clause 4.6.4.4, physical page 66: a script file replaces every child. */
final class ScriptReplacementReadingTest extends TestCase
{
    public function testDirectAndRoleMappedScriptSupplementsIgnoreDescendantStreams(): void
    {
        foreach ([false, true] as $mapped) {
            $policy = new DerivationPolicy(allowActiveContent: true);
            $source = (new ReadPdfSource(policy: $policy, structureValidation: StructureValidationMode::Strict))->read((new DocumentReader())->read($this->fixture($mapped)), 'script.pdf');
            $output = (new HtmlDeriver($policy))->derive($source);
            self::assertContains('const supported = 1;', array_map(static fn ($asset): string => $asset->bytes, $output->assets));
            self::assertStringNotContainsString('ignored bytes', $output->html);
        }
    }

    public function testARejectedScriptDoesNotSuppressOriginalContent(): void
    {
        $this->expectException(UnsupportedPdfFeature::class);
        (new ReadPdfSource())->read((new DocumentReader())->read($this->fixture()), 'blocked.pdf');
    }

    public function testASameNamedPdfRoleDoesNotSuppressItsChildren(): void
    {
        $this->expectException(UnsupportedPdfFeature::class);
        (new ReadPdfSource(policy: new DerivationPolicy(allowActiveContent: true)))->read((new DocumentReader())->read($this->fixture(pdfRole: true)), 'other.pdf');
    }

    public function testOtherRelationshipsDoNotSuppressTheSourceChildren(): void
    {
        $bytes = new FileBytes(str_replace('/Supplement', '/Data      ', $this->fixture()->bytes));
        $this->expectException(UnsupportedPdfFeature::class);
        (new ReadPdfSource(policy: new DerivationPolicy(allowActiveContent: true)))->read((new DocumentReader())->read($bytes), 'data.pdf');
    }

    private function fixture(bool $mapped = false, bool $pdfRole = false): FileBytes
    {
        $fixture = new PdfFixture();
        return $fixture->build([
            4 => $fixture->stream('ignored bytes', '/Filter /UnsupportedCodec'),
            5 => '<< /Type /StructTreeRoot /K 6 0 R /ParentTree 14 0 R /RoleMap << /script /P >> >>',
            7 => '<< /Type /StructElem /S ' . ($mapped ? '/CustomScript /NS 19 0 R' : '/script' . ($pdfRole ? '' : ' /NS 17 0 R')) . ' /P 6 0 R /Pg 3 0 R /K 18 0 R /AF [15 0 R] >>',
            14 => '<< /Nums [0 [18 0 R]] >>',
            15 => '<< /Type /Filespec /UF (source.js) /AFRelationship /Supplement /EF << /UF 16 0 R >> >>',
            16 => $fixture->stream('const supported = 1;', '/Type /EmbeddedFile /Subtype /text#2Fjavascript'),
            17 => '<< /Type /Namespace /NS (http://www.w3.org/1999/xhtml) >>',
            18 => '<< /Type /StructElem /S /P /P 7 0 R /Pg 3 0 R /K 0 >>',
            19 => '<< /Type /Namespace /NS (urn:script) /RoleMapNS << /CustomScript [/script 17 0 R] >> >>',
        ]);
    }
}
