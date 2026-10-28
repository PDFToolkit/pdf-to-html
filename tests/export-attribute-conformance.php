<?php

declare(strict_types=1);

use PDF\Html\HtmlDeriver;
use PDF\Html\Model\AssociatedFile;
use PDF\Html\Model\AttributeObject;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\StructureProperties;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\TextContent;

require dirname(__DIR__) . '/vendor/autoload.php';

$source = new StructureElement('attributes', 'P', [new TextContent('Energy')], properties: new StructureProperties(id: 'attributes', classes: ['theme']), attributes: [
    new AttributeObject('UserProperties', ['P' => [
        ['N' => 'Énergie', 'V' => 0],
        ['N' => 'énergie', 'V' => 1],
        ['N' => '温度+°C', 'V' => 20],
    ]]),
]);

$fragment = new StructureElement('fragment', 'Div', associatedFiles: [
    new AssociatedFile('shared.html', 'text/html', 'Alternative', '<section><div id="cascade" class="placement explicit">Shared class cascade</div></section>'),
]);
$list = new StructureElement('list', 'L', [new StructureElement('item', 'LI', [
    new StructureElement('label', 'Lbl', [new TextContent('1.')], properties: new StructureProperties(id: 'list-label', classes: ['placement'])),
    new StructureElement('body', 'LBody', [new TextContent('Item')]),
])]);

echo (new HtmlDeriver())->derive(new TaggedDocument('attributes.pdf', [$source, $fragment, $list], classMap: [
    'theme' => [new AttributeObject('CSS-3.00', ['--Accent' => 'red', '--accent' => 'blue', 'color' => 'var(--Accent)'])],
    'placement' => [new AttributeObject('Layout', ['Placement' => 'Block'])],
    'explicit' => [new AttributeObject('CSS-3.00', ['display' => 'flex'])],
]))->html;
