<?php

declare(strict_types=1);

use PDF\Html\PdfToHtml;
use PDF\Reading\FileBytes;

require dirname(__DIR__) . '/vendor/autoload.php';

$output = $argv[1] ?? throw new InvalidArgumentException('An output directory is required.');
if (!is_dir($output) && !mkdir($output, 0777, true)) {
    throw new RuntimeException('Cannot create the sample image export directory.');
}
$source = dirname(__DIR__) . '/tests/Fixtures/sample-depths.pdf';
$manifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/tests/Fixtures/sample-depths.json'), true, flags: JSON_THROW_ON_ERROR);
$result = (new PdfToHtml())->convert(new FileBytes((string) file_get_contents($source)), 'sample-depths.pdf');
file_put_contents($output . '/derived.html', $result->html);
$assets = [];
foreach ($result->assets as $asset) {
    file_put_contents($output . '/' . $asset->filename, $asset->bytes);
    $assets[] = ['filename' => $asset->filename, 'mediaType' => $asset->mediaType, 'sha256' => hash('sha256', $asset->bytes)];
}
$report = ['sourceSha256' => hash_file('sha256', $source), 'htmlSha256' => hash('sha256', $result->html), 'cases' => $manifest['cases'], 'assets' => $assets, 'diagnostics' => $result->diagnostics];
file_put_contents($output . '/report.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);
echo json_encode(['output' => $output, 'cases' => count($manifest['cases']), 'htmlSha256' => $report['htmlSha256']], JSON_THROW_ON_ERROR) . PHP_EOL;
