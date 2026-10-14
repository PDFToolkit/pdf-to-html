<?php

declare(strict_types=1);

require dirname(__DIR__).'/Attributes/standalone-bootstrap.php';

use PDF\Html\Forms\FieldType;
use PDF\Html\Model\FormField;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\Widget;
use PDF\Html\Script\ScriptDeriver;

if (($argv[1] ?? '') === '--without-form') {
    $document = new TaggedDocument('without-form.pdf', [], documentScripts:['app.alert(this.app === app ? "document without form" : "incorrect document this");'], pageScripts:[1 => ['O' => 'app.alert(this.app === app ? "page without form" : "incorrect page this");']], pageLabels:[1 => '1']);
    echo (new ScriptDeriver())->derive($document, []);
    exit;
}

$amount = new FormField('amount', FieldType::Text, value:'1', actions:['V' => 'event.rc = Number(event.value) >= 0;']);
$total = new FormField('total', FieldType::Text, value:'0', actions:['C' => 'event.value = doubleAmount(this.getField("amount").value);', 'F' => 'event.value = "$" + event.value;']);
$document = new TaggedDocument('runtime.pdf', [], documentScripts:[
    'var sharedSeed = 3; var methods = "a document variable"; function doubleAmount(value) { return Number(value) * 2; } this.getField("amount").value = "4";',
    'sharedSeed += 2; this.getField("shared").value = sharedSeed; this.getField("context").value = String(app.activeDocs[0] === this && methods === "a document variable");',
], pageScripts:[1 => ['O' => 'this.getField("shared").value = sharedSeed + 1;']], pageLabels:[1 => '1']);

echo (new ScriptDeriver())->derive($document, [new Widget($amount), new Widget($amount), new Widget($total), new Widget(new FormField('__proto__', FieldType::Text, value:'safe')), new Widget(new FormField('locked', FieldType::Choice, 1, value:'FR')), new Widget(new FormField('shared', FieldType::Text, value:'0')), new Widget(new FormField('context', FieldType::Text, value:'unset'))]);
