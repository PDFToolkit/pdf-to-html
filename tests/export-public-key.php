<?php

declare(strict_types=1);

use PDF\Html\PdfToHtml;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\Security\PublicKeyAuthentication;
use PDF\Reading\Security\PublicKeyReadPermissions;
use PDF\Reading\Security\SourceIntegrityPolicy;
use PDF\Security\CertificateChain;
use PDF\Security\OpenSslIdentity;
use PDF\Security\OpenSslRecipientDecrypter;
use PDF\Security\PublicKeyPermissionByteOrder;
use PDF\Security\X509Certificate;
use PDF\Syntax\Object\StringObject\HexadecimalString;

require dirname(__DIR__) . '/vendor/autoload.php';

$directory = $argv[1] ?? throw new InvalidArgumentException('Choose the generated test directory.');
$name = $argv[2] ?? throw new InvalidArgumentException('Choose a source PDF.');
$identityName = $argv[3] ?? 'recipient';
$required = ($argv[4] ?? '') === 'require-mac';
$identity = new OpenSslIdentity(
    new CertificateChain(new X509Certificate(new HexadecimalString(file_get_contents($directory . '/' . $identityName . '.der')))),
    file_get_contents($directory . '/' . $identityName . '-key.pem'),
);
$reader = new DocumentReader(authentication: new PublicKeyAuthentication(
    new OpenSslRecipientDecrypter($identity),
    PublicKeyPermissionByteOrder::MostSignificantFirst,
));
$converter = new PdfToHtml(reader: $reader, sourceIntegrity: $required ? SourceIntegrityPolicy::RequirePdfMac : SourceIntegrityPolicy::AcceptUnprotected);
$file = new FileBytes(file_get_contents($directory . '/' . $name));
$result = $converter->convert($file, $name);
$parsed = $reader->read($file);
$permissions = $parsed->permissions();
if (!$permissions instanceof PublicKeyReadPermissions) {
    throw new RuntimeException('Expected verified public-key permissions.');
}
$reuse = null;
if (!$required) {
    $reuse = $converter->convert(new FileBytes(file_get_contents(__DIR__ . '/Fixtures/tagged.pdf')), 'plain.pdf')->html;
}
echo json_encode([
    'html' => $result->html,
    'mask' => $permissions->effectiveMask(),
    'mac' => $parsed->mac()?->value,
    'plainReuseHtml' => $reuse,
    'diagnostics' => array_map(static fn ($finding): array => ['code' => $finding->code], $result->diagnostics),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
