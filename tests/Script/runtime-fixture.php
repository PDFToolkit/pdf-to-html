<?php

declare(strict_types=1);

require dirname(__DIR__).'/Attributes/standalone-bootstrap.php';

use PDF\Html\Forms\FieldType;
use PDF\Html\Forms\ChoiceOption;
use PDF\Html\Forms\FormActionPolicy;
use PDF\Html\Forms\ResetFormAction;
use PDF\Html\Forms\SubmitFormAction;
use PDF\Html\Forms\WidgetDeriver;
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

if (($argv[1] ?? '') === '--calculation-order') {
    $total = new FormField('total', FieldType::Text, value: '0', actions: ['C' => 'event.value = Number(this.getField("intermediate").value) + 1;']);
    $intermediate = new FormField('intermediate', FieldType::Text, value: '0', actions: ['C' => 'event.value = Number(this.getField("source").value) * 2;']);
    $source = new FormField('source', FieldType::Text, value: '3');
    echo (new ScriptDeriver())->derive(new TaggedDocument('order.pdf', [], calculationOrder: ['absent', 'intermediate', 'total', 'intermediate']), [new Widget($total), new Widget($intermediate), new Widget($source)]);
    exit;
}

if (($argv[1] ?? '') === '--primary-widget-action') {
    $field = new FormField('action', FieldType::Button, flags: 65536);
    echo (new ScriptDeriver())->derive(new TaggedDocument('primary.pdf', []), [
        new Widget($field, actions: ['A' => 'app.alert("first-primary");']),
        new Widget($field, actions: ['A' => 'app.alert("second-primary");']),
    ]);
    exit;
}

if (($argv[1] ?? '') === '--form-actions') {
    $fields = [
        new FormField('Person.Name', FieldType::Text, value: 'Current name', defaultValue: 'Ada', mappingName: 'customer_name'),
        new FormField('Person.Address.City', FieldType::Text, value: 'Current city', defaultValue: 'Paris'),
        new FormField('Person.NoDefault', FieldType::Text, value: 'Present'),
        new FormField('Person.Empty', FieldType::Text),
        new FormField('Person.Secret', FieldType::Text, 4, value: 'Private', defaultValue: 'Private default'),
        new FormField('Personality.Name', FieldType::Text, value: 'Unrelated current', defaultValue: 'Unrelated default'),
        new FormField('Choice.Several', FieldType::Choice, 2097152, value: ['FR'], options: [new ChoiceOption('FR', 'France'), new ChoiceOption('BE', 'Belgium'), new ChoiceOption('DE', 'Germany')], defaultValue: ['BE', 'DE']),
        new FormField('Choice.NoDefault', FieldType::Choice, 131072, value: 'FR', options: [new ChoiceOption('FR', 'France'), new ChoiceOption('BE', 'Belgium')]),
        new FormField('ResetPerson', FieldType::Button, 65536, resetAction: new ResetFormAction(['Person'])),
        new FormField('ResetExcluded', FieldType::Button, 65536, resetAction: new ResetFormAction(['Person'], true)),
        new FormField('ResetNone', FieldType::Button, 65536, resetAction: new ResetFormAction([])),
        new FormField('ResetAll', FieldType::Button, 65536, resetAction: new ResetFormAction(null, true)),
        new FormField('SubmitPerson', FieldType::Button, 65536, submitAction: new SubmitFormAction('https://example.test/submit', 4, ['Person'])),
        new FormField('SubmitExcluded', FieldType::Button, 65536, submitAction: new SubmitFormAction('https://example.test/submit', 5, ['Person'])),
        new FormField('SubmitAll', FieldType::Button, 65536, submitAction: new SubmitFormAction('https://example.test/submit', 6)),
        new FormField('SubmitNone', FieldType::Button, 65536, submitAction: new SubmitFormAction('https://example.test/submit', 4, [])),
        new FormField('SubmitCoordinates', FieldType::Button, 65536, submitAction: new SubmitFormAction('https://example.test/submit', 20, []), mappingName: ' '),
    ];
    $policy = new FormActionPolicy(true, true);
    $widgets = [];
    $bindings = [];
    $controls = [];
    foreach ($fields as $index => $field) {
        $widget = new Widget($field, page: 1, rectangle: [0, 0, 100, 20]);
        $id = 'action-field-' . $index;
        $rendering = (new WidgetDeriver(formActions: $policy))->derive($widget, $id);
        $widgets[] = $widget;
        $bindings[] = new WidgetBinding($widget, $id);
        $controls[] = ['tag' => $rendering->field->tag, 'attributes' => $rendering->field->attributes, 'options' => array_map(static fn ($option): array => ['value' => $option->exportValue, 'text' => $option->displayValue], $field->options)];
    }
    $document = new TaggedDocument('actions.pdf', [], documentScripts: ['throw new Error("disabled-document-script");']);
    echo json_encode(['script' => (new ScriptDeriver(false, $policy))->derive($document, $widgets, $bindings), 'controls' => $controls], JSON_THROW_ON_ERROR);
    exit;
}

if (($argv[1] ?? '') === '--page-lifecycle') {
    $document = new TaggedDocument('pages.pdf', [], documentScripts: ['function pageMessage(kind, event) { app.alert(kind + ":" + this.pageNum + ":" + event.name + ":" + (this === app.activeDocs[0])); }'], pageScripts: [1 => ['O' => 'pageMessage.call(this, "open", event);', 'C' => 'pageMessage.call(this, "close", event);'], 3 => ['O' => 'pageMessage.call(this, "open", event);', 'C' => 'pageMessage.call(this, "close", event);']], pageLabels: [1 => '1', 3 => '3']);
    echo (new ScriptDeriver())->derive($document, []);
    exit;
}

if (($argv[1] ?? '') === '--format-values') {
    $field = new FormField('raw', FieldType::Text, value: '10', actions: ['F' => 'event.value = "$" + event.value;']);
    $inspect = new FormField('inspect', FieldType::Text, actions: ['C' => 'event.value = this.getField("raw").value + ":" + this.getField("raw").valueAsString;']);
    echo (new ScriptDeriver())->derive(new TaggedDocument('formatted.pdf', []), [new Widget($field), new Widget($inspect)]);
    exit;
}

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

$amount = new FormField('amount', FieldType::Text, value:'1', actions:['V' => 'event.rc = Number(event.value) >= 0;'], defaultValue:'1');
$total = new FormField('total', FieldType::Text, value:'0', actions:['C' => 'event.value = doubleAmount(this.getField("amount").value);', 'F' => 'event.value = "$" + event.value;']);
$document = new TaggedDocument('runtime.pdf', [], documentScripts:[
    'var sharedSeed = 3; var methods = "a document variable"; function doubleAmount(value) { return Number(value) * 2; } this.getField("amount").value = "4";',
    'sharedSeed += 2; this.getField("shared").value = sharedSeed; this.getField("context").value = String(app.activeDocs[0] === this && methods === "a document variable");',
], pageScripts:[1 => ['O' => 'this.getField("shared").value = sharedSeed + 1;']], pageLabels:[1 => '1']);

echo (new ScriptDeriver(formActions: new FormActionPolicy(true)))->derive($document, [new Widget($amount), new Widget($amount), new Widget($total), new Widget(new FormField('__proto__', FieldType::Text, value:'safe')), new Widget(new FormField('locked', FieldType::Choice, 1, value:'FR')), new Widget(new FormField('shared', FieldType::Text, value:'0')), new Widget(new FormField('context', FieldType::Text, value:'unset'))]);
