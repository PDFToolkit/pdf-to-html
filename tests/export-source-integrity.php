<?php

declare(strict_types=1);

use PDF\Html\AuthenticationProvider;
use PDF\Html\PdfToHtml;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\ReadDocument;
use PDF\Reading\Security\SourceIntegrityPolicy;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\PreparedPassword;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $bytes = file_get_contents($argv[1] ?? throw new InvalidArgumentException('Supply a fixture.'));
    if ($bytes === false) {
        throw new RuntimeException('Cannot load the fixture.');
    }
    $authentication = new StandardPasswordAuthentication(new PreparedPassword($argv[2] ?? throw new InvalidArgumentException('Supply the public test password.')));
    $provider = ($argv[3] ?? 'native') === 'provider' ? new class ($authentication) implements AuthenticationProvider {
        public function __construct(private readonly StandardPasswordAuthentication $authentication)
        {
        }

        public function authenticate(FileBytes $encryptedFile, string $filename, SourceIntegrityPolicy $sourceIntegrity): ReadDocument
        {
            return (new DocumentReader(authentication: $this->authentication, sourceIntegrity: $sourceIntegrity))->read($encryptedFile);
        }
    } : null;
    $converter = new PdfToHtml(
        reader: $provider === null ? new DocumentReader(authentication: $authentication) : new DocumentReader(),
        authentication: $provider,
        sourceIntegrity: SourceIntegrityPolicy::RequirePdfMac,
    );
    $result = $converter->convert(new FileBytes($bytes), 'authenticated.pdf');
    echo json_encode(['ok' => true, 'html' => $result->html], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'error' => $error::class, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR) . "\n";
}
