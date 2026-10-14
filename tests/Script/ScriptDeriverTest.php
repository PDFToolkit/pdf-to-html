<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Script;

use PDF\Html\Forms\FieldType;
use PDF\Html\DerivationException;
use PDF\Html\DerivationLimits;
use PDF\Html\HtmlDeriver;
use PDF\Html\Model\FormField;
use PDF\Html\Model\AttributeObject;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\StructureProperties;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\Widget;
use PDF\Html\Script\ScriptDeriver;
use PDF\Html\Script\WidgetBinding;
use PDF\Html\DerivationPolicy;
use PHPUnit\Framework\TestCase;

final class ScriptDeriverTest extends TestCase
{
    public function testDocumentAndFieldActionsAreExplicitAndDocumentScoped(): void
    {
        $document = new TaggedDocument('sample.pdf', [], documentScripts:['this.getField("price").value = 2;'], pageScripts:[1 => ['O' => 'this.pageNum = 0;']], pageLabels:[1 => '1']);
        $field = new FormField('price', FieldType::Text, value:'1', actions:['V' => 'event.rc = Number(event.value) >= 0;','C' => 'event.value = 42;','F' => 'event.value = "$" + event.value;']);
        $deriver = new ScriptDeriver();
        $output = $deriver->derive($document, [new Widget($field),new Widget($field)]);
        self::assertNotNull($output);
        self::assertStringContainsString('this.getField("price").value = 2;', $output);
        self::assertSame(1, substr_count($output, 'event.rc = Number(event.value) >= 0;'));
        self::assertStringContainsString('class Field', $output);
        self::assertStringContainsString('IntersectionObserver', $output);
        self::assertStringNotContainsString('new Function', $output);
        self::assertStringNotContainsString('eval(', $output);
        self::assertStringNotContainsString('function(event){\\n', $output);
        self::assertNotEmpty($deriver->diagnostics);
    }
    public function testNoUnusedRuntimeForPlainDocuments(): void
    {
        self::assertNull((new ScriptDeriver())->derive(new TaggedDocument('simple.pdf', []), []));
    }
    public function testSharedFieldsKeepEveryWidgetsOwnAdditionalActions(): void
    {
        $field = new FormField('shared', FieldType::Text, value: 'initial', actions: [
            'V' => 'event.rc = event.value !== "invalid";',
            'Fo' => 'app.alert("legacy focus fallback");',
        ]);
        $widgets = [
            new Widget($field, actions: ['Fo' => 'app.alert("first widget focus");', 'U' => 'this.getField("shared").value = "first";']),
            new Widget($field, actions: ['Fo' => 'app.alert("second widget focus");', 'U' => 'this.getField("shared").value = "second";']),
        ];
        $output = (new ScriptDeriver())->derive(new TaggedDocument('widgets.pdf', []), $widgets);
        self::assertNotNull($output);
        self::assertSame(1, substr_count($output, 'event.rc = event.value !== "invalid";'));
        self::assertStringContainsString('app.alert("first widget focus");', $output);
        self::assertStringContainsString('app.alert("second widget focus");', $output);
        self::assertStringContainsString('this.getField("shared").value = "first";', $output);
        self::assertStringContainsString('this.getField("shared").value = "second";', $output);
        self::assertStringNotContainsString('legacy focus fallback', $output);
    }

    public function testLegacyMixedFieldActionsRemainSpecificToEachWidget(): void
    {
        $first = new FormField('legacy', FieldType::Text, actions: ['Fo' => 'app.alert("legacy first widget");']);
        $second = new FormField('legacy', FieldType::Text, actions: ['Fo' => 'app.alert("legacy second widget");']);
        $output = (new ScriptDeriver())->derive(new TaggedDocument('legacy.pdf', []), [new Widget($first), new Widget($second)]);
        self::assertNotNull($output);
        self::assertStringContainsString('app.alert("legacy first widget");', $output);
        self::assertStringContainsString('app.alert("legacy second widget");', $output);
    }

    public function testDocumentAndPageScriptsDoNotRequireAnAcroForm(): void
    {
        $document = new TaggedDocument('plain.pdf', [], documentScripts:['app.alert("document");'], pageScripts:[1 => ['O' => 'app.alert("page");']]);
        $output = (new ScriptDeriver())->derive($document, []);
        self::assertNotNull($output);
        self::assertStringNotContainsString('if (!rootForm) return', $output);
        self::assertStringContainsString('rootForm?.addEventListener', $output);
        self::assertStringContainsString('app.alert("document");', $output);
        self::assertStringContainsString('app.alert("page");', $output);
    }

    public function testRuntimePlaceholderNamesInsideTrustedScriptsArePreservedLiterally(): void
    {
        $output = (new ScriptDeriver())->derive(new TaggedDocument('plain.pdf', [], documentScripts:['var marker = "__METHODS__ __CONFIG__ __NUM_PAGES__";']), []);
        self::assertNotNull($output);
        self::assertStringContainsString('var marker = "__METHODS__ __CONFIG__ __NUM_PAGES__";', $output);
    }

    public function testWidgetOnlyActionsAreReportedByTheSafeProfile(): void
    {
        $document = new TaggedDocument('widget-only.pdf', [new StructureElement('form', 'Form', [
            new Widget(new FormField('name', FieldType::Text), actions: ['Fo' => 'app.alert("focus");']),
        ])]);
        $result = (new HtmlDeriver())->derive($document);
        self::assertSame([], $result->assets);
        self::assertContains('scripts-disabled', array_map(static fn ($diagnostic): string => $diagnostic->code, $result->diagnostics));
    }

    public function testWidgetActionsCannotBypassCumulativeOutputBudget(): void
    {
        $document = new TaggedDocument('widget-only.pdf', [new StructureElement('form', 'Form', [
            new Widget(new FormField('name', FieldType::Text), actions: ['Fo' => str_repeat('x', 2048)]),
        ])]);
        $this->expectException(DerivationException::class);
        (new HtmlDeriver(limits: new DerivationLimits(maximumOutputBytes: 1024)))->derive($document);
    }

    public function testNestedSharedFieldWidgetsBindToTheirFinalHtmlIdentifiers(): void
    {
        $field = new FormField('shared', FieldType::Text);
        $inner = new StructureElement('inner', 'Form', [new Widget($field, actions: ['Fo' => 'app.alert("inner");'])], properties: new StructureProperties(id: 'inner-original'), attributes: [new AttributeObject('HTML-5.00', ['id' => '__proto__'])]);
        $outer = new StructureElement('outer', 'Form', [$inner, new Widget($field, actions: ['Fo' => 'app.alert("outer");'])], properties: new StructureProperties(id: 'outer-original'), attributes: [new AttributeObject('HTML-5.00', ['id' => 'constructor'])]);
        $result = (new HtmlDeriver(new DerivationPolicy(allowActiveContent: true)))->derive(new TaggedDocument('nested.pdf', [$outer]));
        self::assertMatchesRegularExpression('/<input id="__proto__".*<input id="constructor"/', $result->html);
        self::assertCount(1, $result->assets);
        self::assertStringContainsString('\\u0022id\\u0022:\\u0022__proto__\\u0022', $result->assets[0]->bytes);
        self::assertStringContainsString('\\u0022id\\u0022:\\u0022constructor\\u0022', $result->assets[0]->bytes);
        self::assertStringNotContainsString('inner-original', $result->assets[0]->bytes);
        self::assertStringNotContainsString('outer-original', $result->assets[0]->bytes);
    }

    public function testExplicitBindingsCannotAssignTheSameControlTwice(): void
    {
        $widget = new Widget(new FormField('shared', FieldType::Text));
        $this->expectException(DerivationException::class);
        (new ScriptDeriver())->derive(new TaggedDocument('duplicate.pdf', []), [$widget, $widget], [new WidgetBinding($widget, 'control'), new WidgetBinding($widget, 'control')]);
    }

    public function testExplicitBindingsCannotBeAssignedToAnotherWidget(): void
    {
        $widget = new Widget(new FormField('first', FieldType::Text));
        $other = new Widget(new FormField('second', FieldType::Text));
        $this->expectException(DerivationException::class);
        (new ScriptDeriver())->derive(new TaggedDocument('mismatch.pdf', []), [$widget], [new WidgetBinding($other, 'control')]);
    }
}
