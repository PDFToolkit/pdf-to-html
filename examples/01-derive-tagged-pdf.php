<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PDF\Html\PdfToHtml;
use PDF\Reading\FileBytes;
use PDF\Reading\ReaderLimits;

// File access belongs to this application, not to the library's source objects.
$input = $argv[1] ?? dirname(__DIR__) . '/tests/Fixtures/tagged.pdf';
$output = $argv[2] ?? dirname(__DIR__) . '/_build/derived.html';
if (!stream_is_local($input) || !is_file($input)) {
    throw new RuntimeException('The input must identify an existing local PDF file.');
}
$stream = fopen($input, 'rb');
if ($stream === false) {
    throw new RuntimeException('The input PDF could not be opened.');
}
try {
    $maximum = (new ReaderLimits())->maxFileBytes;
    $bytes = stream_get_contents($stream, $maximum + 1);
} finally {
    fclose($stream);
}
if ($bytes === false || strlen($bytes) > $maximum) {
    throw new RuntimeException('The input PDF exceeds the configured file limit.');
}

// Parsing, interpretation and derivation are separate injectable objects.
// The default profile performs no network access and emits no active scripts.
$result = (new PdfToHtml())->convert(new FileBytes($bytes), basename($input));
$directory = dirname($output);
if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
    throw new RuntimeException('The output directory could not be created.');
}
if (file_put_contents($output, $result->html) === false) {
    throw new RuntimeException('The derived HTML could not be written.');
}
foreach ($result->assets as $asset) {
    if (file_put_contents($directory . '/' . $asset->filename, $asset->bytes) === false) {
        throw new RuntimeException('A derived asset could not be written.');
    }
}

echo json_encode([
    'input' => basename($input),
    'html' => basename($output),
    'htmlSha256' => hash('sha256', $result->html),
    'cssBytes' => strlen($result->css),
    'assets' => array_map(static fn ($asset): array => ['filename' => $asset->filename, 'mediaType' => $asset->mediaType, 'sha256' => hash('sha256', $asset->bytes)], $result->assets),
    'diagnostics' => $result->diagnostics,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
