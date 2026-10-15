<?php

declare(strict_types=1);

namespace PDF\Html\Tests;

use PDF\Html\AuthenticationProvider;
use PDF\Html\DerivationException;
use PDF\Html\DerivationLimits;
use PDF\Html\DerivationPolicy;
use PDF\Html\HtmlDeriver;
use PDF\Html\Model\AuthenticatedPermissions;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\TextContent;
use PDF\Html\PdfToHtml;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\TaggedPdfSource;
use PDF\Html\Tests\Reading\PdfFixture;
use PDF\Reading\EncryptedPdfException;
use PDF\Reading\FileBytes;
use PDF\Reading\ParseException;
use PDF\Reading\UnsupportedPdfFeature;
use PDF\Reading\DocumentReader;
use PDF\Reading\ReadDocument;
use PDF\Reading\Security\SourceIntegrityPolicy;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\PreparedPassword;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthenticationBoundaryTest extends TestCase
{
    public function testOnlyAVerifiedEncryptedInputInvokesTheAuthenticationProvider(): void
    {
        $file = $this->encryptedFixture();
        $provider = $this->createMock(AuthenticationProvider::class);
        $provider->expects(self::once())->method('authenticate')->with(self::identicalTo($file), 'encrypted.pdf', SourceIntegrityPolicy::AcceptUnprotected)->willReturn($this->authenticatedDocument($file));
        $result = (new PdfToHtml(authentication: $provider))->convert($file, 'encrypted.pdf');
        self::assertStringContainsString('External tagged PDF', $result->html);
        self::assertStringContainsString('data-pdf-perms="-3392"', $result->html);
        self::assertContains('viewer-permissions', array_column($result->diagnostics, 'code'));
    }

    public function testEncryptedInputWithoutAProviderRetainsItsTypedException(): void
    {
        $this->expectException(EncryptedPdfException::class);
        (new PdfToHtml())->convert($this->encryptedFixture(), 'encrypted.pdf');
    }

    public function testAuthenticationWithoutVerifiedPermissionsIsRejected(): void
    {
        $provider = $this->createMock(AuthenticationProvider::class);
        $file = $this->encryptedFixture();
        $limits = new \PDF\Reading\ReaderLimits();
        $provider->expects(self::once())->method('authenticate')->willReturn(new ReadDocument($file, new \PDF\Syntax\FileStructure\FileHeader(), new \PDF\Syntax\Object\DictionaryType(), new \PDF\Reading\CrossReference\Index(), $limits, new \PDF\Reading\ReadBudget($limits), new \PDF\Reading\StreamDecoder($limits)));
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage('verified permissions');
        (new PdfToHtml(authentication: $provider))->convert($file, 'encrypted.pdf');
    }

    public function testUnauthenticatedPermissionsCannotBeConstructedNormally(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AuthenticatedPermissions(-44, false);
    }

    public function testAProviderCannotPassUnauthenticatedPermissionsThroughAHydrator(): void
    {
        // Reflective hydrators can bypass value-object constructors; the public boundary must still reject this flag.
        $reflection = new \ReflectionClass(AuthenticatedPermissions::class);
        $permissions = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('mask')->setValue($permissions, -44);
        $reflection->getProperty('authenticated')->setValue($permissions, false);
        $file = $this->encryptedFixture();
        $provider = $this->createMock(AuthenticationProvider::class);
        $provider->expects(self::once())->method('authenticate')->willReturn($this->authenticatedDocument($file));
        $source = $this->createMock(TaggedPdfSource::class);
        $source->expects(self::once())->method('read')->willReturn(new TaggedDocument('encrypted.pdf', [], permissions: $permissions));
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage('verified effective permissions');
        (new PdfToHtml(source: $source, authentication: $provider))->convert($file, 'encrypted.pdf');
    }

    #[DataProvider('adapterErrors')]
    public function testAdapterExceptionsNeverMasqueradeAsReaderAuthenticationSignals(UnsupportedPdfFeature $error): void
    {
        $provider = $this->createMock(AuthenticationProvider::class);
        $provider->expects(self::never())->method('authenticate');
        $source = $this->createMock(TaggedPdfSource::class);
        $source->expects(self::once())->method('read')->willThrowException($error);
        try {
            (new PdfToHtml(source: $source, authentication: $provider))->convert((new PdfFixture())->build(), 'plain.pdf');
            self::fail('The adapter failure must propagate.');
        } catch (UnsupportedPdfFeature $caught) {
            self::assertSame($error, $caught);
        }
    }

    /** @return iterable<string, array{UnsupportedPdfFeature}> */
    public static function adapterErrors(): iterable
    {
        yield 'diagnostic text contains encrypt' => [new UnsupportedPdfFeature('A custom encrypt-related mechanism is unsupported.')];
        yield 'adapter also uses the typed class' => [new EncryptedPdfException(42)];
    }

    public function testAuthenticationProviderFailureIsNotRetriedOrReclassified(): void
    {
        $error = new UnsupportedPdfFeature('Custom encryption authentication failed.');
        $provider = $this->createMock(AuthenticationProvider::class);
        $provider->expects(self::once())->method('authenticate')->willThrowException($error);
        try {
            (new PdfToHtml(authentication: $provider))->convert($this->encryptedFixture(), 'encrypted.pdf');
            self::fail('Authentication errors must propagate.');
        } catch (UnsupportedPdfFeature $caught) {
            self::assertSame($error, $caught);
        }
    }

    #[DataProvider('nullEncryptionEntries')]
    public function testNullEncryptionEntriesDoNotInvokeAuthentication(string $entry, bool $indirect): void
    {
        $plain = (new PdfFixture())->build($indirect ? [20 => 'null'] : []);
        // The trailer follows the xref: adding a key does not change any recorded object or startxref offset.
        $file = new FileBytes(str_replace('/Root 1 0 R >>', '/Root 1 0 R /Encrypt ' . $entry . ' >>', $plain->bytes));
        $provider = $this->createMock(AuthenticationProvider::class);
        $provider->expects(self::never())->method('authenticate');
        $result = (new PdfToHtml(authentication: $provider))->convert($file, 'plain.pdf');
        self::assertStringContainsString('Visible', $result->html);
        self::assertStringNotContainsString('data-pdf-perms', $result->html);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function nullEncryptionEntries(): iterable
    {
        yield 'direct null' => ['null', false];
        yield 'reference to null object' => ['20 0 R', true];
    }

    #[DataProvider('malformedEncryptionEntries')]
    public function testMalformedEncryptionEntriesNeverInvokeAuthentication(string $entry, bool $indirect): void
    {
        $plain = (new PdfFixture())->build($indirect ? [20 => 'false'] : []);
        $file = new FileBytes(str_replace('/Root 1 0 R >>', '/Root 1 0 R /Encrypt ' . $entry . ' >>', $plain->bytes));
        $provider = $this->createMock(AuthenticationProvider::class);
        $provider->expects(self::never())->method('authenticate');
        try {
            (new PdfToHtml(authentication: $provider))->convert($file, 'plain.pdf');
            self::fail('A malformed encryption entry must fail parsing.');
        } catch (ParseException $error) {
            self::assertSame(ParseException::class, $error::class);
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function malformedEncryptionEntries(): iterable
    {
        yield 'false' => ['false', false];
        yield 'name' => ['/NotAnEncryptionDictionary', false];
        yield 'indirect false' => ['20 0 R', true];
    }

    public function testMismatchedNativeDerivationLimitsAreRejectedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('same policy and derivation limits');
        new PdfToHtml(source: new ReadPdfSource(derivationLimits: new DerivationLimits(maximumAssetBytes: 32)));
    }

    public function testEquivalentNativePoliciesAndLimitsCanUseDifferentImmutableInstances(): void
    {
        $source = new ReadPdfSource(policy: new DerivationPolicy(allowHtmlNamespace: false), derivationLimits: new DerivationLimits(maximumAssetBytes: 32));
        $deriver = new HtmlDeriver(new DerivationPolicy(allowHtmlNamespace: false), new DerivationLimits(maximumAssetBytes: 32));
        $result = (new PdfToHtml(source: $source, deriver: $deriver))->convert((new PdfFixture())->build(), 'plain.pdf');
        self::assertStringContainsString('Visible', $result->html);
    }

    private function authenticatedDocument(FileBytes $source): ReadDocument
    {
        return (new DocumentReader(authentication: new StandardPasswordAuthentication(new PreparedPassword('native-user'))))->read($source);
    }

    private function encryptedFixture(): FileBytes
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/tagged-aes256.pdf');
        if ($bytes === false) {
            throw new \LogicException('The independently encrypted tagged fixture is missing.');
        }
        return new FileBytes($bytes);
    }

}
