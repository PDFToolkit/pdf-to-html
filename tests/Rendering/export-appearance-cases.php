<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$cases = [];
foreach (['normal-size' => '[10 20 70 50]', 'double-size' => '[30 40 150 100]'] as $name => $rectangle) {
    $file = (new \PDF\Html\Tests\Rendering\AppearanceCases())->document([
        9 => '<< /Type /Annot /Subtype /Widget /StructParent 0 /P 3 0 R /Rect ' . $rectangle . ' /FT /Btn /Ff 65536 /T (Action) /H /P /AS /On /AP << /N << /Off 13 0 R /On 10 0 R >> /D << /On 11 0 R >> /R << /On 12 0 R >> >> >>',
    ]);
    $source = (new \PDF\Html\Reading\ReadPdfSource(structureValidation: \PDF\Html\Reading\StructureValidationMode::Strict))->read((new \PDF\Reading\DocumentReader())->read($file), 'appearance.pdf');
    $widget = $source->structure[0]->children[0]->children[0];
    if (!$widget instanceof \PDF\Html\Model\Widget) {
        throw new \LogicException('The independently authored fixture must expose a widget.');
    }
    foreach (['N' => $widget->appearance, 'D' => $widget->downAppearance, 'R' => $widget->rolloverAppearance] as $state => $nodes) {
        $rendering = (new \PDF\Html\Rendering\GraphicPdfExporter())->export(new \PDF\Html\Model\ContentGroup($nodes));
        $cases[$name . '-' . $state] = [
            'source' => base64_encode($file->bytes),
            'state' => $state,
            'pdf' => base64_encode($rendering->bytes),
            'width' => $rendering->widthPoints,
            'height' => $rendering->heightPoints,
        ];
    }
}
foreach (['nested-artifact' => '0 0 1 rg 5 5 5 5 re f', 'artifact-only' => ''] as $name => $expected) {
    $fixture = new \PDF\Html\Tests\Reading\PdfFixture();
    $file = (new \PDF\Html\Tests\Rendering\AppearanceCases())->document([
        10 => $fixture->stream('/Fm Do', '/Type /XObject /Subtype /Form /BBox [0 0 30 15] /Resources << /XObject << /Fm 18 0 R >> >>'),
        18 => $fixture->stream('/Artifact BMC 1 0 0 rg 0 0 30 15 re f EMC ' . $expected, '/Type /XObject /Subtype /Form /BBox [0 0 30 15] /Resources << >>'),
    ]);
    $source = (new \PDF\Html\Reading\ReadPdfSource())->read((new \PDF\Reading\DocumentReader())->read($file), 'artifact.pdf');
    $widget = $source->structure[0]->children[0]->children[0];
    if (!$widget instanceof \PDF\Html\Model\Widget) {
        throw new \LogicException('The independently authored fixture must expose a widget.');
    }
    $rendering = (new \PDF\Html\Rendering\GraphicPdfExporter())->export(new \PDF\Html\Model\ContentGroup($widget->appearance));
    $cases[$name] = [
        'source' => base64_encode($file->bytes),
        'state' => 'N',
        'pdf' => base64_encode($rendering->bytes),
        'width' => $rendering->widthPoints,
        'height' => $rendering->heightPoints,
        'expectedNestedProgram' => $expected,
    ];
}
echo json_encode($cases, JSON_THROW_ON_ERROR);
