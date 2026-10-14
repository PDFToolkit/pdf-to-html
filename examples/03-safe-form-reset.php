<?php

declare(strict_types=1);

use PDF\Html\DerivationPolicy;
use PDF\Html\Forms\FieldType;
use PDF\Html\Forms\FormActionPolicy;
use PDF\Html\Forms\ResetFormAction;
use PDF\Html\HtmlDeriver;
use PDF\Html\Model\FormField;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\StructureProperties;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\TextContent;
use PDF\Html\Model\Widget;

require dirname(__DIR__).'/vendor/autoload.php';

// Source values distinguish the current /V from /DV, which ResetForm must restore.
$name = new FormField('Person.Name', FieldType::Text, value: 'Grace Hopper', defaultValue: 'Ada Lovelace');
$reset = new FormField('ResetPerson', FieldType::Button, flags: 65536, resetAction: new ResetFormAction(['Person']));
$document = new TaggedDocument('reset-example.pdf', [
    new StructureElement('person', 'Form', [new StructureElement('label', 'Lbl', [new TextContent('Name')]), new Widget($name)], properties: new StructureProperties(id: 'name')),
    new StructureElement('reset', 'Form', [new Widget($reset, caption: 'Restore Person defaults')]),
], title: 'A targeted PDF form reset', language: 'en');
// Enable only library-owned reset logic. Embedded PDF ECMAScript remains disabled.
$result = (new HtmlDeriver(new DerivationPolicy(formActions: new FormActionPolicy(allowReset: true))))->derive($document);
$output = $argv[1] ?? __DIR__.'/safe-form-reset.html';
if ($output === '' || str_contains($output, "\0") || preg_match('~^[a-z][a-z0-9+.-]*://~i', $output) === 1) {
    throw new InvalidArgumentException('Choose a local HTML output path.');
}
if (file_put_contents($output, $result->html, LOCK_EX) !== strlen($result->html)) {
    throw new RuntimeException('The application could not write the complete HTML.');
}
foreach ($result->assets as $asset) {
    $path = dirname($output).DIRECTORY_SEPARATOR.$asset->filename;
    if (file_put_contents($path, $asset->bytes, LOCK_EX) !== strlen($asset->bytes)) {
        throw new RuntimeException('The application could not write a runtime asset.');
    }
}
echo $output.PHP_EOL;
