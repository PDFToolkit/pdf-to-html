<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PDF\Html\HtmlDeriver;
use PDF\Html\Model\AttributeObject;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\StructureProperties;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\TextContent;

$counter = 0;
$element = static function (string $type, array $children = [], array $attributes = []) use (&$counter): StructureElement {
    return new StructureElement('case-' . ++$counter, $type, array_values($children), attributes: array_values($attributes));
};
$text = static fn (string $value): TextContent => new TextContent($value);
$nestedList = $element('L', [$element('LI', [$text('nested')])]);
$cases = [
    'split-paragraph' => [$element('Div', [$element('P', [$element('Sub', [$text('before'), $nestedList, $text('after')])])])],
    'captions-and-table-heading' => [$element('Table', [$element('TR', [$element('TH', [$element('Sect', [$element('H2', [$text('heading')])])])]), $element('Caption', [$text('caption'), $element('L', [$element('LI', [$text('list in caption')])])])])],
    'nested-list-caption' => [$element('L', [$element('Caption', [$text('outer caption')]), $element('L', [$element('Caption', [$text('nested caption')]), $element('LI', [$text('entry')])])])],
    'description-list' => [$element('L', [$element('LI', [$element('Lbl', [$text('term')]), $element('LBody', [$text('definition')])])], [new AttributeObject('List', ['ListNumbering' => 'Description'])])],
    'orphan-list-in-description' => [$element('Div', [new StructureElement('description', 'L', [
        $element('LI', [$element('Lbl', [$text('before term')]), $element('LBody', [$text('before definition')])]),
        $element('L', [$element('LI', [$text('orphan entry')])]),
        $element('LI', [$element('Lbl', [$text('after term')]), $element('LBody', [$text('after definition')])]),
    ], properties: new StructureProperties(id: 'description'), attributes: [new AttributeObject('List', ['ListNumbering' => 'Description'])])])],
    'list-in-description-body' => [$element('L', [$element('LI', [
        $element('Lbl', [$text('term')]),
        $element('LBody', [$element('L', [$element('LI', [$text('nested definition')])])]),
    ])], [new AttributeObject('List', ['ListNumbering' => 'Description'])])],
    'orphan-lists-in-descriptions' => [$element('L', [
        $element('L', [$element('L', [$element('LI', [$text('deeply nested orphan')])])], [new AttributeObject('List', ['ListNumbering' => 'Description'])]),
    ], [new AttributeObject('List', ['ListNumbering' => 'Description'])])],
    'code-lines' => [$element('Code', [$element('Sub', [$text('x = 3')]), $element('Sub', [$text('y = 7')])])],
    'math' => [new StructureElement('math-wrapper', 'P', [new StructureElement('formula', 'Formula', [new StructureElement('math', 'math', [new StructureElement('mi', 'mi', [$text('x')], 'http://www.w3.org/1998/Math/MathML')], 'http://www.w3.org/1998/Math/MathML')], properties: new StructureProperties(alternateDescription: 'x'))])],
];
$output = [];
foreach ($cases as $name => $structure) {
    $output[$name] = (new HtmlDeriver())->derive(new TaggedDocument($name . '.pdf', $structure))->html;
}
echo json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
