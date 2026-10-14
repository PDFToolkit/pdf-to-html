<?php

declare(strict_types=1);

require __DIR__ . '/Attributes/standalone-bootstrap.php';

use PDF\Html\Html\ContentModelRepair;
use PDF\Html\Html\Element;
use PDF\Html\Html\ExpansionText;
use PDF\Html\Html\PageContentIdentifiers;
use PDF\Html\Html\Serializer;
use PDF\Html\Html\Text;

$cases = [];
foreach (['text', 'multiple', 'image', 'block', 'math', 'widget-expansion', 'omitted'] as $case) {
    $identifiers = new PageContentIdentifiers();
    $markers = [new Element('span', ['id' => 'PDF-Page-1', 'role' => 'doc-pagebreak', 'aria-label' => 'I'])];
    if ($case === 'multiple') {
        $markers[] = new Element('span', ['id' => 'PDF-Page-2', 'role' => 'doc-pagebreak', 'aria-label' => 'II']);
    }
    $content = match ($case) {
        'text', 'multiple' => [new Text('Actual replacement')],
        'image' => [new Element('img', ['src' => 'image.png', 'alt' => 'Image'])],
        'block' => [new Element('table', ['id' => 'table'], [new Element('tr', [], [new Element('td', [], [new Text('Cell')])])])],
        'math' => [new Element('math', ['xmlns' => 'http://www.w3.org/1998/Math/MathML'], [new Element('mi', [], [new Text('x')])])],
        'widget-expansion' => [(new ExpansionText())->wrap(new Element('input', ['id' => 'person', 'type' => 'text', 'title' => 'Identité complète']), 'Identity')],
        'omitted' => [],
    };
    $navigation = new Element('nav', [], array_map(static fn (Element $marker): Element => new Element('a', ['href' => '#' . $marker->attributes['id']], [new Text($marker->attributes['aria-label'])]), $markers));
    $nodes = [$navigation];
    if ($case === 'widget-expansion') {
        $nodes[] = new Element('label', ['for' => 'person'], [new Text('Name')]);
    }
    array_push($nodes, ...$identifiers->identify($markers, $content));
    $body = new Element('body', [], $nodes);
    $html = new Element('html', ['lang' => 'en'], [new Element('head', [], [new Element('meta', ['charset' => 'UTF-8']), new Element('title', [], [new Text($case)])]), $body]);
    $output = (new ContentModelRepair())->repair($html)[0];
    $cases[$case] = ['html' => '<!DOCTYPE html>' . (new Serializer())->serialize($output), 'diagnostics' => $identifiers->diagnostics];
}
echo json_encode($cases, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
