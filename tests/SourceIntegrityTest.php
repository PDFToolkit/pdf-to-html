<?php

declare(strict_types=1);

namespace PDF\Html\Tests;

use PDF\Html\AuthenticationProvider;
use PDF\Html\DerivationException;
use PDF\Html\Model\AuthenticatedPermissions;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\PdfToHtml;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\TaggedPdfSource;
use PDF\Html\Tests\Reading\PdfFixture;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\ParseException;
use PDF\Reading\ReadDocument;
use PDF\Reading\Security\AuthenticationFailed;
use PDF\Reading\Security\SourceIntegrityPolicy;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\PreparedPassword;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourceIntegrityTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function protectedSources(): iterable
    {
        $manifest = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/source-integrity/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['fixtures'] as $row) {
            yield $row['file'] => [$row['file'], $row['location'], $row['sha256']];
        }
    }

    #[DataProvider('protectedSources')]
    public function testNativeAndProviderPathsPreserveAuthenticatedMacPermissionsAndTaggedContent(string $file, string $location, string $sha256): void
    {
        $source = $this->fixture($file);
        self::assertSame($sha256, hash('sha256', $source->bytes));
        foreach (['native-user' => -7488, 'native-owner' => -4] as $password => $mask) {
            $reader = $this->reader($password);
            $graph = $reader->read($source);
            self::assertSame($location, $graph->mac()?->value);
            self::assertSame(-7488, $graph->permissions()?->mask);
            self::assertSame($mask, $graph->permissions()?->effectiveMask());
            self::assertSame($mask, (new ReadPdfSource())->read($graph, 'protected.pdf')->permissions?->mask);
            foreach ([false, true] as $external) {
                $provider = $this->createMock(AuthenticationProvider::class);
                $provider->expects($external ? self::once() : self::never())->method('authenticate')
                    ->with(self::identicalTo($source), 'protected.pdf', SourceIntegrityPolicy::RequirePdfMac)
                    ->willReturn($graph);
                $result = (new PdfToHtml(reader: $external ? new DocumentReader() : $reader, authentication: $provider, sourceIntegrity: SourceIntegrityPolicy::RequirePdfMac))->convert($source, 'protected.pdf');
                self::assertStringContainsString('External tagged PDF', $result->html);
                self::assertStringContainsString('data-pdf-perms="' . $mask . '"', $result->html);
                self::assertContains('viewer-permissions', array_column($result->diagnostics, 'code'));
            }
        }
    }

    #[DataProvider('protectedSources')]
    public function testWrongCredentialsAndTamperingFailBeforeSourceInterpretation(string $file, string $location, string $sha256): void
    {
        $original = $this->fixture($file);
        $changed = new FileBytes(substr_replace($original->bytes, 'X', 10, 1));
        foreach ([['incorrect', $original, AuthenticationFailed::class], ['native-user', $changed, ParseException::class]] as [$password, $bytes, $failure]) {
            foreach ([false, true] as $external) {
                $provider = $this->createMock(AuthenticationProvider::class);
                $provider->expects($external ? self::once() : self::never())->method('authenticate')->willReturnCallback(
                    fn (FileBytes $source, string $filename, SourceIntegrityPolicy $policy): ReadDocument => $this->reader($password, $policy)->read($source)
                );
                $source = $this->createMock(TaggedPdfSource::class);
                $source->expects(self::never())->method('read');
                try {
                    (new PdfToHtml(reader: $external ? new DocumentReader() : $this->reader($password), source: $source, authentication: $provider))->convert($bytes, 'protected.pdf');
                    self::fail('Unverified content must not reach tagged interpretation.');
                } catch (ParseException $error) {
                    self::assertInstanceOf($failure, $error);
                }
            }
        }
    }

    public function testRequiredIntegrityStrengthensAnInjectedPermissiveReaderBeforeCatalogInterpretation(): void
    {
        $source = $this->createMock(TaggedPdfSource::class);
        $source->expects(self::never())->method('read');
        $provider = $this->createMock(AuthenticationProvider::class);
        $provider->expects(self::never())->method('authenticate');
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('source-integrity policy');
        (new PdfToHtml(reader: new DocumentReader(), source: $source, authentication: $provider, sourceIntegrity: SourceIntegrityPolicy::RequirePdfMac))->convert((new PdfFixture())->build([1 => 'null']), 'clear.pdf');
    }

    public function testAnInjectedRequiredReaderCannotBeWeakenedByTheConverterDefault(): void
    {
        $source = $this->createMock(TaggedPdfSource::class);
        $source->expects(self::never())->method('read');
        $file = $this->unprotectedEncrypted();
        $provider = $this->createMock(AuthenticationProvider::class);
        $provider->expects(self::once())->method('authenticate')->with(self::identicalTo($file), 'protected.pdf', SourceIntegrityPolicy::RequirePdfMac)->willReturn($this->reader('native-user')->read($file));
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage('verified PDF MAC');
        (new PdfToHtml(reader: new DocumentReader(sourceIntegrity: SourceIntegrityPolicy::RequirePdfMac), source: $source, authentication: $provider))->convert($file, 'protected.pdf');
    }

    public function testAProviderCannotIgnoreTheConverterRequiredMacPolicy(): void
    {
        $file = $this->unprotectedEncrypted();
        $provider = $this->createMock(AuthenticationProvider::class);
        $provider->expects(self::once())->method('authenticate')->with(self::identicalTo($file), 'protected.pdf', SourceIntegrityPolicy::RequirePdfMac)->willReturn($this->reader('native-user')->read($file));
        $source = $this->createMock(TaggedPdfSource::class);
        $source->expects(self::never())->method('read');
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage('verified PDF MAC');
        (new PdfToHtml(source: $source, authentication: $provider, sourceIntegrity: SourceIntegrityPolicy::RequirePdfMac))->convert($file, 'protected.pdf');
    }

    public function testAnAuthenticatedMacGraphOfOtherBytesCannotAuthenticateTheInput(): void
    {
        $file = $this->fixture('tagged-mac-r6-standalone.pdf');
        $other = $this->reader('native-user')->read($this->fixture('tagged-mac-r6-attached.pdf'));
        self::assertNotNull($other->permissions());
        self::assertNotNull($other->mac());
        $provider = $this->createMock(AuthenticationProvider::class);
        $provider->expects(self::once())->method('authenticate')->willReturn($other);
        $source = $this->createMock(TaggedPdfSource::class);
        $source->expects(self::never())->method('read');
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage('exact supplied source bytes');
        (new PdfToHtml(source: $source, authentication: $provider, sourceIntegrity: SourceIntegrityPolicy::RequirePdfMac))->convert($file, 'protected.pdf');
    }

    public function testSourceIdentityChecksAllowEqualBytesInAnotherImmutableFileValue(): void
    {
        $file = $this->fixture('tagged-mac-r6-standalone.pdf');
        $copy = new FileBytes($file->bytes);
        $graph = $this->reader('native-user')->read($copy);
        self::assertNotSame($file, $graph->sourceRevision()->source);
        $provider = $this->createMock(AuthenticationProvider::class);
        $provider->expects(self::once())->method('authenticate')->willReturn($graph);
        $result = (new PdfToHtml(authentication: $provider, sourceIntegrity: SourceIntegrityPolicy::RequirePdfMac))->convert($file, 'protected.pdf');
        self::assertStringContainsString('data-pdf-perms="-7488"', $result->html);
    }

    /** @return iterable<string, array{?AuthenticatedPermissions, bool}> */
    public static function invalidAdaptedPermissions(): iterable
    {
        foreach ([false, true] as $provider) {
            yield ($provider ? 'provider/' : 'native/') . 'missing permissions' => [null, $provider];
            yield ($provider ? 'provider/' : 'native/') . 'elevated owner permissions' => [new AuthenticatedPermissions(-4, true), $provider];
        }
    }

    #[DataProvider('invalidAdaptedPermissions')]
    public function testMacValidationDoesNotPermitAnAdapterToDropOrElevateUsagePermissions(?AuthenticatedPermissions $permissions, bool $external): void
    {
        $file = $this->fixture('tagged-mac-r6-standalone.pdf');
        $provider = $this->createMock(AuthenticationProvider::class);
        $provider->expects($external ? self::once() : self::never())->method('authenticate')->willReturn($this->reader('native-user')->read($file));
        $source = $this->createMock(TaggedPdfSource::class);
        $source->expects(self::once())->method('read')->willReturn(new TaggedDocument('protected.pdf', [], permissions: $permissions));
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage('verified effective permissions');
        (new PdfToHtml(reader: $external ? new DocumentReader() : $this->reader('native-user'), source: $source, authentication: $provider, sourceIntegrity: SourceIntegrityPolicy::RequirePdfMac))->convert($file, 'protected.pdf');
    }

    private function reader(string $password, SourceIntegrityPolicy $policy = SourceIntegrityPolicy::AcceptUnprotected): DocumentReader
    {
        return new DocumentReader(authentication: new StandardPasswordAuthentication(new PreparedPassword($password)), sourceIntegrity: $policy);
    }

    private function fixture(string $name): FileBytes
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/source-integrity/' . $name);
        self::assertNotFalse($bytes);
        return new FileBytes($bytes);
    }

    private function unprotectedEncrypted(): FileBytes
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/tagged-aes256.pdf');
        self::assertNotFalse($bytes);
        return new FileBytes($bytes);
    }
}
