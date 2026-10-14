<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$cases = [];
foreach ((new \PDF\Html\Tests\Rendering\RenderingCases())->documents() as $name => $file) {
    $document = (new \PDF\Html\Reading\ReadPdfSource())->read((new \PDF\Reading\DocumentReader())->read($file), 'graphics.pdf');
    $group = new \PDF\Html\Model\ContentGroup($document->structure[0]->children[0]->children);
    $rendering = (new \PDF\Html\Rendering\GraphicPdfExporter())->export($group);
    $cases[$name] = ['pdf' => base64_encode($rendering->bytes), 'width' => $rendering->widthPoints, 'height' => $rendering->heightPoints];
}
echo json_encode($cases, JSON_THROW_ON_ERROR);
