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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthenticationBoundaryTest extends TestCase
{
    public function testOnlyAVerifiedEncryptedInputInvokesTheAuthenticationProvider(): void
    {
        $file = $this->encryptedFixture();
        $provider = $this->createMock(AuthenticationProvider::class);
        $provider->expects(self::once())->method('authenticate')->with(self::identicalTo($file), 'encrypted.pdf')->willReturn($this->authenticatedDocument());
        $source = $this->createMock(TaggedPdfSource::class);
        $source->expects(self::never())->method('read');
        $result = (new PdfToHtml(source: $source, authentication: $provider))->convert($file, 'encrypted.pdf');
        self::assertStringContainsString('Authenticated content', $result->html);
        self::assertStringContainsString('data-pdf-perms="-44"', $result->html);
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
        $provider->expects(self::once())->method('authenticate')->willReturn(new TaggedDocument('encrypted.pdf', [new StructureElement('p', 'P', [new TextContent('Unverified content')])]));
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage('verified permissions');
        (new PdfToHtml(authentication: $provider))->convert($this->encryptedFixture(), 'encrypted.pdf');
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
        $provider = $this->createMock(AuthenticationProvider::class);
        $provider->expects(self::once())->method('authenticate')->willReturn(new TaggedDocument('encrypted.pdf', [], permissions: $permissions));
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage('verified permissions');
        (new PdfToHtml(authentication: $provider))->convert($this->encryptedFixture(), 'encrypted.pdf');
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

    private function authenticatedDocument(): TaggedDocument
    {
        return new TaggedDocument('encrypted.pdf', [new StructureElement('p', 'P', [new TextContent('Authenticated content')])], permissions: new AuthenticatedPermissions(-44, true));
    }

    private function encryptedFixture(): FileBytes
    {
        // Original pypdf 6.20.1 RC4-128 fixture, independently decrypted with password "boundary-user".
        // The provider owns actual decryption; this fixture verifies that the reader encounters a real /Encrypt dictionary.
        $bytes = base64_decode('JVBERi0xLjMKJeLjz9MKMSAwIG9iago8PAovUHJvZHVjZXIgPGJiMGY0NjY3ZGM+Cj4+CmVuZG9iagoyIDAgb2JqCjw8Ci9UeXBlIC9QYWdlcwovQ291bnQgMQovS2lkcyBbIDQgMCBSIF0KPj4KZW5kb2JqCjMgMCBvYmoKPDwKL1R5cGUgL0NhdGFsb2cKL1BhZ2VzIDIgMCBSCj4+CmVuZG9iago0IDAgb2JqCjw8Ci9UeXBlIC9QYWdlCi9SZXNvdXJjZXMgPDwKPj4KL01lZGlhQm94IFsgMC4wIDAuMCAyMDAgMjAwIF0KL1BhcmVudCAyIDAgUgo+PgplbmRvYmoKNSAwIG9iago8PAovViAyCi9SIDMKL0xlbmd0aCAxMjgKL1AgNDI5NDk2NzI5MgovRmlsdGVyIC9TdGFuZGFyZAovTyA8OTA5NTBjMzgwZGRlMTFlYTMzZjkxY2E2NDc1Y2Y3NjEzNmNhZDIwOGYzN2NjOGU2MmE3M2I4NDcyOGQwYWQ5MD4KL1UgPDhkZjQxZDkwY2Q3YWNiMWM2YTcwMTkwNjFkNjI4Yjg4MjhiZjRlNWU0ZTc1OGE0MTY0MDA0ZTU2ZmZmYTAxMDg+Cj4+CmVuZG9iagp4cmVmCjAgNgowMDAwMDAwMDAwIDY1NTM1IGYgCjAwMDAwMDAwMTUgMDAwMDAgbiAKMDAwMDAwMDA1OSAwMDAwMCBuIAowMDAwMDAwMTE4IDAwMDAwIG4gCjAwMDAwMDAxNjcgMDAwMDAgbiAKMDAwMDAwMDI2MSAwMDAwMCBuIAp0cmFpbGVyCjw8Ci9TaXplIDYKL1Jvb3QgMyAwIFIKL0luZm8gMSAwIFIKL0lEIFsgPDM1Mzk2MzMyMzA2MjYyNjE2NTYzMzgzMjY1MzE2MjM1NjMzNjMzNjM2MTYyNjU2NjM1NjE2NjYxNjU2NjMxMzE+IDwzNTM5NjMzMjMwNjI2MjYxNjU2MzM4MzI2NTMxNjIzNTYzMzYzMzYzNjE2MjY1NjYzNTYxNjY2MTY1NjYzMTMxPiBdCi9FbmNyeXB0IDUgMCBSCj4+CnN0YXJ0eHJlZgo0NzYKJSVFT0YK', true);
        if ($bytes === false) {
            throw new \LogicException('The embedded independent PDF fixture must be valid base64.');
        }
        return new FileBytes($bytes);
    }
}
