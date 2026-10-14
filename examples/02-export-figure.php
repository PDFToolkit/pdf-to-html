<?php

declare(strict_types=1);

use PDF\Html\Model\ContentGroup;
use PDF\Html\Model\StructureElement;
use PDF\Html\DerivationLimits;
use PDF\Html\DerivationPolicy;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Rendering\GraphicPdfExporter;
use PDF\Html\RoleResolver;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Serialization\PdfFileOutput;

require dirname(__DIR__).'/vendor/autoload.php';

// This CLI deliberately performs I/O; the source adapter and exporter receive only objects/bytes.
$input = $argv[1] ?? dirname(__DIR__).'/tests/Fixtures/tagged.pdf';
$output = $argv[2] ?? __DIR__.'/figure.pdf';
$bytes = file_get_contents($input);
if ($bytes === false) {
    throw new RuntimeException('The application could not read its chosen input file.');
}
$document = (new ReadPdfSource())->read((new DocumentReader())->read(new FileBytes($bytes)), basename($input));
$roles = new RoleResolver($document->roleMappings, new DerivationLimits(), new DerivationPolicy());
$pending = array_reverse($document->structure);
while ($pending !== []) {
    $element = array_pop($pending);
    if (!$element instanceof StructureElement) {
        continue;
    }
    if ($roles->resolve($element)->type === 'Figure') {
        // GraphicProgram contains exact PDF operands, resources and caller contexts.
        // Export selects this figure's paints, while replaying preceding state and text advances.
        $rendering = (new GraphicPdfExporter())->export(new ContentGroup($element->children));
        (new PdfFileOutput())->write($output, $rendering->bytes);
        echo json_encode(['output' => $output, 'sourcePage' => $rendering->sourcePage, 'widthPoints' => $rendering->widthPoints, 'heightPoints' => $rendering->heightPoints, 'sha256' => hash('sha256', $rendering->bytes)], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
        exit(0);
    }
    array_push($pending, ...array_reverse($element->children));
}
throw new RuntimeException('The source has no figure with original graphics to export.');
