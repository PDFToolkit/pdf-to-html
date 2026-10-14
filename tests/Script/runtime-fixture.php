<?php

declare(strict_types=1);

require dirname(__DIR__).'/Attributes/standalone-bootstrap.php';

use PDF\Html\Forms\FieldType;
use PDF\Html\DerivationPolicy;
use PDF\Html\HtmlDeriver;
use PDF\Html\Model\AttributeObject;
use PDF\Html\Model\FormField;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\StructureProperties;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\TextContent;
use PDF\Html\Model\Widget;
use PDF\Html\Script\ScriptDeriver;
use PDF\Html\Script\WidgetBinding;

if (($argv[1] ?? '') === '--bound-widget-actions') {
    $field = new FormField('shared-bound', FieldType::Text, value: 'initial');
    $outerWidget = new Widget($field, actions: ['Fo' => 'app.alert("outer-focus");', 'U' => 'this.getField("shared-bound").value = "outer-value";']);
    $innerWidget = new Widget($field, actions: ['Fo' => 'app.alert("inner-focus");', 'U' => 'this.getField("shared-bound").value = "inner-value";']);
    $inner = new StructureElement('inner-form', 'Form', [new StructureElement('inner-label', 'Lbl', [new TextContent('Inner')]), $innerWidget], properties: new StructureProperties(id: 'inner-original'), attributes: [new AttributeObject('HTML-5.00', ['id' => '__proto__'])]);
    $foreign = new StructureElement('foreign', 'input', [], 'http://www.w3.org/1999/xhtml', properties: new StructureProperties(id: 'foreign'), attributes: [new AttributeObject('HTML-5.00', ['name' => 'shared-bound', 'value' => 'unrelated', 'type' => 'text', 'form' => 'acroform_id'])]);
    $outer = new StructureElement('outer-form', 'Form', [$foreign, $inner, $outerWidget], properties: new StructureProperties(id: 'outer-original'), attributes: [new AttributeObject('HTML-5.00', ['id' => 'constructor', 'name' => 'renamed-control'])]);
    $result = (new HtmlDeriver(new DerivationPolicy(allowActiveContent: true)))->derive(new TaggedDocument('bound-widgets.pdf', [$outer]));
    $scripts = array_values(array_filter($result->assets, static fn ($asset): bool => $asset->mediaType === 'text/javascript'));
    echo json_encode(['html' => $result->html, 'script' => $scripts[0]->bytes], JSON_THROW_ON_ERROR);
    exit;
}

if (($argv[1] ?? '') === '--missing-bound-widget') {
    $widget = new Widget(new FormField('shared-bound', FieldType::Text, value: 'initial'), actions: ['Fo' => 'app.alert("missing-focus");']);
    echo (new ScriptDeriver())->derive(new TaggedDocument('missing-widget.pdf', []), [$widget], [new WidgetBinding($widget, 'missing-control')]);
    exit;
}

if (($argv[1] ?? '') === '--without-form') {
    $document = new TaggedDocument('without-form.pdf', [], documentScripts:['app.alert(this.app === app ? "document without form" : "incorrect document this");'], pageScripts:[1 => ['O' => 'app.alert(this.app === app ? "page without form" : "incorrect page this");']], pageLabels:[1 => '1']);
    echo (new ScriptDeriver())->derive($document, []);
    exit;
}

if (($argv[1] ?? '') === '--widget-actions') {
    $field = new FormField('shared-widget', FieldType::Text, value: 'initial', actions: ['V' => 'event.rc = event.value !== "invalid";', 'Fo' => 'reportWidget("fallback", event);']);
    $document = new TaggedDocument('widget-actions.pdf', [], documentScripts: ['function reportWidget(label, event) { app.alert(label + ":" + event.target.name + ":" + event.value); }']);
    echo (new ScriptDeriver())->derive($document, [
        new Widget($field, actions: ['Fo' => 'reportWidget("first-focus", event);', 'U' => 'this.getField("shared-widget").value = "first-value";']),
        new Widget($field, actions: ['Fo' => 'reportWidget("second-focus", event);', 'U' => 'this.getField("shared-widget").value = "second-value";']),
        new Widget(new FormField('legacy-widget', FieldType::Text, value: 'legacy', actions: ['Fo' => 'reportWidget("legacy-first-focus", event);'])),
        new Widget(new FormField('legacy-widget', FieldType::Text, value: 'legacy', actions: ['Fo' => 'reportWidget("legacy-second-focus", event);'])),
    ]);
    exit;
}

$amount = new FormField('amount', FieldType::Text, value:'1', actions:['V' => 'event.rc = Number(event.value) >= 0;']);
$total = new FormField('total', FieldType::Text, value:'0', actions:['C' => 'event.value = doubleAmount(this.getField("amount").value);', 'F' => 'event.value = "$" + event.value;']);
$document = new TaggedDocument('runtime.pdf', [], documentScripts:[
    'var sharedSeed = 3; var methods = "a document variable"; function doubleAmount(value) { return Number(value) * 2; } this.getField("amount").value = "4";',
    'sharedSeed += 2; this.getField("shared").value = sharedSeed; this.getField("context").value = String(app.activeDocs[0] === this && methods === "a document variable");',
], pageScripts:[1 => ['O' => 'this.getField("shared").value = sharedSeed + 1;']], pageLabels:[1 => '1']);

echo (new ScriptDeriver())->derive($document, [new Widget($amount), new Widget($amount), new Widget($total), new Widget(new FormField('__proto__', FieldType::Text, value:'safe')), new Widget(new FormField('locked', FieldType::Choice, 1, value:'FR')), new Widget(new FormField('shared', FieldType::Text, value:'0')), new Widget(new FormField('context', FieldType::Text, value:'unset'))]);
