<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Reading;

use PDF\Html\DerivationException;
use PDF\Html\Model\Widget;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Reading\ReadingException;
use PDF\Html\Reading\ReadingLimits;
use PDF\Html\Signatures\SignatureValidationProvider;
use PDF\Html\Signatures\SignatureValidationRequest;
use PDF\Html\Signatures\SignatureValidationResult;
use PDF\Html\Signatures\SignatureValidationStatus;
use PDF\Reading\DocumentReader;
use PDF\Reading\ReadDocument;
use PHPUnit\Framework\TestCase;

final class SignatureValidationReadingTest extends TestCase
{
    public function testUnsignedAndUnvalidatedSignaturesAreDistinct(): void
    {
        self::assertSame('unsigned', $this->widgets($this->document(false))[0]->signatureStatus);
        self::assertSame('not-validated', $this->widgets($this->document())[0]->signatureStatus);
    }

    public function testProviderReceivesExactSourceAndInheritedFieldOnceForBothWidgets(): void
    {
        $document = $this->document();
        $provider = new class () implements SignatureValidationProvider {
            /** @var list<SignatureValidationRequest> */
            public array $requests = [];
            public function validate(SignatureValidationRequest $request): SignatureValidationResult
            {
                $this->requests[] = $request;
                return new SignatureValidationResult($request, SignatureValidationStatus::Indeterminate, '<untrusted> explanation');
            }
        };
        [$first, $second] = $this->widgets($document, $provider);
        self::assertCount(1, $provider->requests);
        self::assertSame($document, $provider->requests[0]->document);
        self::assertSame('Approval', $provider->requests[0]->fieldName);
        self::assertSame($document->resolve($document->catalog()->get('TestSignature')), $provider->requests[0]->signature);
        self::assertSame($first->signatureValidation, $second->signatureValidation);
        self::assertSame('indeterminate', $first->signatureStatus);
        self::assertSame('<untrusted> explanation', $first->signatureValidation?->message);
    }

    public function testAResultCannotBeReusedAcrossSources(): void
    {
        $provider = new class () implements SignatureValidationProvider {
            private ?SignatureValidationResult $cached = null;
            public function validate(SignatureValidationRequest $request): SignatureValidationResult
            {
                return $this->cached ??= new SignatureValidationResult($request, SignatureValidationStatus::Valid, 'Application policy passed.');
            }
        };
        $this->widgets($this->document(), $provider);
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage('exact requested source and field');
        $this->widgets($this->document(), $provider);
    }

    public function testProviderTextUsesTheCumulativeSourceBudget(): void
    {
        $provider = new class () implements SignatureValidationProvider {
            public function validate(SignatureValidationRequest $request): SignatureValidationResult
            {
                return new SignatureValidationResult($request, SignatureValidationStatus::Indeterminate, str_repeat('x', 1025));
            }
        };
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('cumulative source text budget');
        $this->widgets($this->document(), $provider, new ReadingLimits(maximumTextBytes: 1024));
    }

    private function document(bool $signed = true): ReadDocument
    {
        $fixture = new PdfFixture();
        return (new DocumentReader())->read($fixture->build([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /AcroForm << /Fields [11 0 R] >> /TestSignature 15 0 R >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources <<>> /Contents 4 0 R /Annots [12 0 R 9 0 R] >>',
            4 => $fixture->stream(''),
            5 => '<< /Type /StructTreeRoot /K 6 0 R /ParentTree 14 0 R /ParentTreeNextKey 3 >>',
            6 => '<< /Type /StructElem /S /Document /P 5 0 R /K [7 0 R 10 0 R] >>',
            7 => '<< /Type /StructElem /S /Form /P 6 0 R /Pg 3 0 R /K << /Type /OBJR /Obj 12 0 R >> >>',
            9 => '<< /Type /Annot /Subtype /Widget /StructParent 2 /P 3 0 R /Rect [0 30 80 50] /Parent 11 0 R >>',
            10 => '<< /Type /StructElem /S /Form /P 6 0 R /Pg 3 0 R /K << /Type /OBJR /Obj 9 0 R >> >>',
            11 => '<< /FT /Sig /T (Approval) /Kids [12 0 R 9 0 R] '.($signed ? '/V 15 0 R' : '').' >>',
            12 => '<< /Type /Annot /Subtype /Widget /StructParent 1 /P 3 0 R /Rect [0 0 80 20] /Parent 11 0 R >>',
            14 => '<< /Nums [1 7 0 R 2 10 0 R] >>',
            15 => '<< /Type /Sig /SubFilter /adbe.pkcs7.detached /Contents <> /ByteRange [0 1 3 4] >>',
        ]));
    }

    /** @return array{Widget, Widget} */
    private function widgets(ReadDocument $document, ?SignatureValidationProvider $provider = null, ?ReadingLimits $limits = null): array
    {
        $source = (new ReadPdfSource(limits: $limits ?? new ReadingLimits(), signatureValidation: $provider))->read($document, 'signed.pdf');
        $first = $source->structure[0]->children[0]->children[0];
        $second = $source->structure[0]->children[1]->children[0];
        self::assertInstanceOf(Widget::class, $first);
        self::assertInstanceOf(Widget::class, $second);
        return [$first, $second];
    }
}
