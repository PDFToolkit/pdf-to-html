<?php

declare(strict_types=1);

use PDF\Html\PdfToHtml;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\PdfDocEncodedPassword;
use PDF\Security\PreparedPassword;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

$bytes = file_get_contents($argv[1] ?? throw new InvalidArgumentException('Choose a test fixture explicitly.'));
if ($bytes === false) {
    throw new RuntimeException('The chosen fixture cannot be read.');
}
$password = getenv('PDFTOOLKIT_TEST_PASSWORD');
if ($password === false) {
    throw new InvalidArgumentException('Set the explicit fixture-test password.');
}
$prepared = ($argv[2] ?? 'modern') === 'historical' ? new PdfDocEncodedPassword(new LiteralString($password)) : new PreparedPassword($password);
$result = (new PdfToHtml(reader: new DocumentReader(authentication: new StandardPasswordAuthentication($prepared))))->convert(new FileBytes($bytes), 'authenticated.pdf');
echo json_encode(['html' => $result->html, 'diagnostics' => array_map(static fn ($finding): array => ['code' => $finding->code, 'message' => $finding->message], $result->diagnostics)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
