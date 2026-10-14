<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$cases = [];
foreach ((new \PDF\Html\Tests\Rendering\RenderingReviewCases())->documents() as $name => $file) {
    if ($name === 'deep-resource') {
        continue;
    }
    $document = (new \PDF\Html\Reading\ReadPdfSource())->read((new \PDF\Reading\DocumentReader())->read($file), 'graphics-context.pdf');
    $figure = $document->structure[0]->children[0];
    if (!$figure instanceof \PDF\Html\Model\StructureElement) {
        throw new \LogicException('The independent fixture requires a first Figure element.');
    }
    $group = new \PDF\Html\Model\ContentGroup($figure->children);
    $rendering = (new \PDF\Html\Rendering\GraphicPdfExporter())->export($group);
    $cases[$name] = ['source' => base64_encode($file->bytes), 'pdf' => base64_encode($rendering->bytes), 'width' => $rendering->widthPoints, 'height' => $rendering->heightPoints];
}
echo json_encode($cases, JSON_THROW_ON_ERROR);
