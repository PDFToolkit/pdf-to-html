<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PDF\Html\PdfToHtml;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\ReaderLimits;
use PDF\Reading\Security\SourceIntegrityPolicy;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\Saslprep;

// The application supplies the file and credential; the library performs no I/O.
$input = $argv[1] ?? dirname(__DIR__) . '/tests/Fixtures/source-integrity/tagged-mac-r6-standalone.pdf';
$output = $argv[2] ?? dirname(__DIR__) . '/_build/authenticated.html';
$password = getenv('PDFTOOLKIT_PASSWORD');
if ($password === false) {
    throw new InvalidArgumentException('Supply PDFTOOLKIT_PASSWORD explicitly; the example fixtures use native-user.');
}
if (!stream_is_local($input) || !is_file($input)) {
    throw new RuntimeException('Choose an existing local PDF input.');
}
$stream = fopen($input, 'rb');
if ($stream === false) {
    throw new RuntimeException('Cannot open the chosen PDF.');
}
try {
    $maximum = (new ReaderLimits())->maxFileBytes;
    $bytes = stream_get_contents($stream, $maximum + 1);
} finally {
    fclose($stream);
}
if ($bytes === false || strlen($bytes) > $maximum) {
    throw new RuntimeException('The PDF exceeds the example source limit.');
}

// MAC verification precedes tagged interpretation. The required policy also
// rejects a clear or stripped input, and strengthens this reader's default.
$reader = new DocumentReader(authentication: new StandardPasswordAuthentication((new Saslprep())->prepare($password)));
$converter = new PdfToHtml(reader: $reader, sourceIntegrity: SourceIntegrityPolicy::RequirePdfMac);
$result = $converter->convert(new FileBytes($bytes), basename($input));

// The original PDF's MAC does not authenticate the derived HTML. Keep the normal
// output security policy and preserve actual user/owner usage permissions.
$directory = dirname($output);
if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
    throw new RuntimeException('Cannot create the explicit output directory.');
}
if (file_put_contents($output, $result->html) === false) {
    throw new RuntimeException('Cannot write the derived HTML.');
}
foreach ($result->assets as $asset) {
    if (file_put_contents($directory . '/' . $asset->filename, $asset->bytes) === false) {
        throw new RuntimeException('Cannot write the derived asset.');
    }
}
echo json_encode(['input' => basename($input), 'html' => basename($output), 'htmlSha256' => hash('sha256', $result->html), 'diagnostics' => $result->diagnostics], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
