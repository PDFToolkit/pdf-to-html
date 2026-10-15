<?php

declare(strict_types=1);

namespace PDF\Html\Tests;

use PDF\Html\AuthenticationProvider;
use PDF\Html\DerivationException;
use PDF\Html\Model\AuthenticatedPermissions;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\PdfToHtml;
use PDF\Html\TaggedPdfSource;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\Security\AuthenticationFailed;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\PdfDocEncodedPassword;
use PDF\Security\PreparedPassword;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Actual qpdf-encrypted tagged files; no mocked plaintext or permission source. */
final class NativeAuthenticationTest extends TestCase
{
    #[DataProvider('cipherRevisions')]
    public function testNativeCredentialsDecryptTaggedContentAndExportEffectivePermissions(string $fixture, bool $modern, int $revision): void
    {
        $reader = $this->reader('native-user', $modern);
        $source = $this->fixture($fixture);
        $parsed = $reader->read($source);
        self::assertSame($revision, $parsed->permissions()?->revision);
        self::assertSame(-3392, $parsed->permissions()?->effectiveMask());
        $tagged = (new ReadPdfSource())->read($parsed, 'protected.pdf');
        self::assertTrue($tagged->permissions?->authenticated);
        self::assertSame(-3392, $tagged->permissions?->mask);
        $provider = $this->createMock(AuthenticationProvider::class);
        $provider->expects(self::never())->method('authenticate');
        $result = (new PdfToHtml(reader: $reader, authentication: $provider))->convert($source, 'protected.pdf');
        self::assertStringContainsString('External tagged PDF', $result->html);
        self::assertStringContainsString('data-pdf-perms="-3392"', $result->html);
        self::assertContains('viewer-permissions', array_column($result->diagnostics, 'code'));
    }

    #[DataProvider('cipherRevisions')]
    public function testOwnerAuthenticationExportsOwnerAccessInsteadOfTheRestrictedUserMask(string $fixture, bool $modern, int $revision): void
    {
        $reader = $this->reader('native-owner', $modern);
        $parsed = $reader->read($this->fixture($fixture));
        self::assertSame($revision, $parsed->permissions()?->revision);
        self::assertSame(-3392, $parsed->permissions()?->mask);
        self::assertSame(-4, $parsed->permissions()?->effectiveMask());
        $result = (new PdfToHtml(reader: $reader))->convert($this->fixture($fixture), 'owner.pdf');
        self::assertStringContainsString('data-pdf-perms="-4"', $result->html);
        self::assertStringNotContainsString('data-pdf-perms="-3392"', $result->html);
    }

    #[DataProvider('cipherCredentials')]
    public function testWrongPasswordNeverFallsBackToTheLegacyAuthenticationPort(string $fixture, bool $modern): void
    {
        $provider = $this->createMock(AuthenticationProvider::class);
        $provider->expects(self::never())->method('authenticate');
        $this->expectException(AuthenticationFailed::class);
        (new PdfToHtml(reader: $this->reader('wrong-password', $modern), authentication: $provider))->convert($this->fixture($fixture), 'protected.pdf');
    }

    public function testPermissionsAreDocumentScopedWhenTheConfiguredConverterIsReused(): void
    {
        $converter = new PdfToHtml(reader: $this->reader('native-user', true));
        self::assertStringContainsString('data-pdf-perms="-3392"', $converter->convert($this->fixture('tagged-aes256.pdf'), 'protected.pdf')->html);
        $plain = $converter->convert($this->fixture('tagged.pdf'), 'plain.pdf');
        self::assertStringContainsString('External tagged PDF', $plain->html);
        self::assertStringNotContainsString('data-pdf-perms', $plain->html);
        self::assertNotContains('viewer-permissions', array_column($plain->diagnostics, 'code'));
    }

    #[DataProvider('invalidAdaptedPermissions')]
    public function testCustomAdaptersCannotDropOrUpgradeVerifiedNativeUserPermissions(?AuthenticatedPermissions $permissions): void
    {
        $source = $this->createMock(TaggedPdfSource::class);
        $source->expects(self::once())->method('read')->willReturn(new TaggedDocument('protected.pdf', [], permissions: $permissions));
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage('verified effective permissions');
        (new PdfToHtml(reader: $this->reader('native-user', true), source: $source))->convert($this->fixture('tagged-aes256.pdf'), 'protected.pdf');
    }

    /** @return iterable<string, array{?AuthenticatedPermissions}> */
    public static function invalidAdaptedPermissions(): iterable
    {
        yield 'omitted permissions' => [null];
        yield 'fabricated owner access' => [new AuthenticatedPermissions(-4, true)];
    }

    /** @return iterable<string, array{string, bool, int}> */
    public static function cipherRevisions(): iterable
    {
        yield 'AES-128, Standard revision 4' => ['tagged-aes128.pdf', false, 4];
        yield 'AES-256, Standard revision 6' => ['tagged-aes256.pdf', true, 6];
    }

    /** @return iterable<string, array{string, bool}> */
    public static function cipherCredentials(): iterable
    {
        foreach (self::cipherRevisions() as $name => [$fixture, $modern]) {
            yield $name => [$fixture, $modern];
        }
    }

    private function reader(string $password, bool $modern): DocumentReader
    {
        return new DocumentReader(authentication: new StandardPasswordAuthentication($modern ? new PreparedPassword($password) : new PdfDocEncodedPassword(new LiteralString($password))));
    }

    private function fixture(string $name): FileBytes
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/' . $name);
        if ($bytes === false) {
            throw new \RuntimeException('A retained authentication fixture is missing.');
        }
        return new FileBytes($bytes);
    }
}
