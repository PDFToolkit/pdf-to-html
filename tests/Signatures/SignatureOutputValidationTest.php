<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Signatures;

use PDF\Document\Signatures\SignatureOutputVerifier;
use PDF\Document\Signatures\SignatureVerificationInput;
use PDF\Document\Signatures\VerifiedSignatureFacts;
use PDF\Html\DerivationException;
use PDF\Html\Signatures\SignatureOutputValidation;
use PDF\Html\Signatures\SignatureValidationRequest;
use PDF\Html\Signatures\SignatureValidationResult;
use PDF\Html\Signatures\SignatureValidationStatus;
use PDF\Html\Signatures\SignatureVerificationSource;
use PDF\Security\OpenSslSignatureOutputVerifier;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignatureOutputValidationTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function signedCases(): iterable
    {
        foreach ((new SignatureFixture())->cases() as $case) {
            yield $case['name'] => [$case['file'], $case['certificate'], $case['status']];
        }
    }

    #[DataProvider('signedCases')]
    public function testIndependentCmsFixtures(string $file, string $certificate, string $expected): void
    {
        $fixtures = new SignatureFixture();
        $request = $fixtures->request($file);
        $result = $fixtures->provider($certificate)->validate($request);
        self::assertSame($expected, $result->status->value, $result->message);
        $result->assertFor($request);
        self::assertNotSame('', $result->message);
        if ($result->status === SignatureValidationStatus::IntegrityVerified) {
            self::assertStringContainsString('Certificate trust, revocation and later PDF changes have not been validated', $result->message);
            self::assertStringContainsString('HTML is not signed', $result->message);
        }
    }

    public function testReconstructedContentsRequiresAnExplicitProvenanceProvider(): void
    {
        $fixtures = new SignatureFixture();
        $original = $fixtures->request();
        $changed = $original->signature->with(new Entry(new NameType('Contents'), new HexadecimalString("\x30\0")));
        $result = $fixtures->provider()->validate(new SignatureValidationRequest($original->document, $changed, $original->fieldName));
        self::assertSame(SignatureValidationStatus::Indeterminate, $result->status);
        self::assertStringContainsString('physical provenance', $result->message);
    }

    /** @return iterable<string, array{list<int>}> */
    public static function invalidRanges(): iterable
    {
        yield 'does not start at file origin' => [[1, 2, 3, 4]];
        yield 'negative length' => [[0, -1, 3, 4]];
        yield 'overlapping segments' => [[0, 5, 3, 4]];
        yield 'past file end' => [[0, 5, 6, 999999999]];
        yield 'too few ranges' => [[0, 5, 6]];
        yield 'extra ranges' => [[0, 5, 6, 7, 8, 9]];
    }

    #[DataProvider('invalidRanges')]
    public function testMalformedByteRangesFailClosed(array $numbers): void
    {
        $fixtures = new SignatureFixture();
        $original = $fixtures->request();
        $changed = $original->signature->with(new Entry(new NameType('ByteRange'), new ArrayType(...array_map(static fn (int $value): IntegerType => new IntegerType($value), $numbers))));
        $result = $fixtures->provider()->validate(new SignatureValidationRequest($original->document, $changed, $original->fieldName));
        self::assertSame(SignatureValidationStatus::Invalid, $result->status);
    }

    public function testTimestampFormatNeedsItsOwnValidationProvider(): void
    {
        $fixtures = new SignatureFixture();
        $original = $fixtures->request();
        $changed = $original->signature->with(new Entry(new NameType('SubFilter'), new NameType('ETSI.RFC3161')));
        $result = $fixtures->provider()->validate(new SignatureValidationRequest($original->document, $changed, $original->fieldName));
        self::assertSame(SignatureValidationStatus::Indeterminate, $result->status);
    }

    public function testVerifiedFactsCannotBeReplayedForAlteredSignedBytes(): void
    {
        $fixtures = new SignatureFixture();
        $input = (new SignatureVerificationSource())->input($fixtures->request(), $fixtures->certificate());
        $facts = (new OpenSslSignatureOutputVerifier())->verify($input);
        $replay = new class ($facts) implements SignatureOutputVerifier {
            public function __construct(private readonly VerifiedSignatureFacts $facts)
            {
            }

            public function verify(SignatureVerificationInput $input): VerifiedSignatureFacts
            {
                return $this->facts;
            }
        };
        $result = (new SignatureOutputValidation($replay, $fixtures->certificate()))->validate($fixtures->request('signed-tampered.pdf'));
        self::assertSame(SignatureValidationStatus::Invalid, $result->status);
        self::assertStringContainsString('exact input/output bytes', $result->message);
    }

    public function testResultCannotBeReboundToAnotherFieldOrRequest(): void
    {
        $fixtures = new SignatureFixture();
        $request = $fixtures->request();
        $result = $fixtures->provider()->validate($request);
        $other = new SignatureValidationRequest($request->document, $request->signature, 'another-field');
        $this->expectException(DerivationException::class);
        $result->assertFor($other);
    }

    public function testInvalidExplanationCannotBecomeHtmlOutput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SignatureValidationResult((new SignatureFixture())->request(), SignatureValidationStatus::Valid, "invalid\0message");
    }
}
