<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Forms;

use PDF\Html\Forms\ChoiceOption;
use PDF\Html\Forms\FieldType;
use PDF\Html\Forms\WidgetDeriver;
use PDF\Html\Model\FormField;
use PDF\Html\Model\Widget;
use PHPUnit\Framework\TestCase;

final class WidgetDeriverTest extends TestCase
{
    private function widget(FormField $field): Widget
    {
        return new Widget($field, page:1, rectangle:[0,0,100,20], pageBox:[0,0,200,200]);
    }
    public function testSubmitResetAndSignatureDoNotInventValidation(): void
    {
        $deriver = new WidgetDeriver();
        $submit = $deriver->derive($this->widget(new FormField('send', FieldType::Button, 65536, actionType:'SubmitForm', actionTarget:'https://example.test/send', submitFlags:12)), 'send')->field;
        self::assertSame('button', $submit->tag);
        self::assertSame('submit', $submit->attributes['type']);
        self::assertSame('get', $submit->attributes['formmethod']);
        $reset = $deriver->derive($this->widget(new FormField('reset', FieldType::Button, 65536, actionType:'ResetForm')), 'reset')->field;
        self::assertSame('reset', $reset->attributes['type']);
        $signature = $deriver->derive($this->widget(new FormField('sign', FieldType::Signature)), 'signature');
        self::assertSame('not-validated', $signature->field->attributes['data-pdf-signature-status']);
        self::assertArrayNotHasKey('onclick', $signature->field->attributes);
        self::assertNotEmpty($signature->diagnostics);
    }
    public function testRadioUsesExportOptionAndAppearanceState(): void
    {
        $field = new FormField('country', FieldType::Button, 32768, options:[new ChoiceOption('FR', 'France')]);
        $rendering = (new WidgetDeriver())->derive(new Widget($field, page:1, rectangle:[0,0,20,20], appearanceState:'France', appearanceStates:['Off','France']), 'country');
        self::assertSame('radio', $rendering->field->attributes['type']);
        self::assertSame('FR', $rendering->field->attributes['value']);
        self::assertArrayHasKey('checked', $rendering->field->attributes);
    }
    public function testChoiceExportDisplaySelectedAndEditableDatalist(): void
    {
        $field = new FormField('countries', FieldType::Choice, 2097152, value:['FR'], options:[new ChoiceOption('FR', 'France'),new ChoiceOption('BE', 'Belgium')], selectedIndices:[1]);
        $rendering = (new WidgetDeriver())->derive($this->widget($field), 'countries');
        self::assertSame('select', $rendering->field->tag);
        self::assertSame('3', $rendering->field->attributes['size']);
        self::assertArrayHasKey('multiple', $rendering->field->attributes);
        self::assertSame('FR', $rendering->field->children[0]->attributes['value']);
        self::assertSame('France', $rendering->field->children[0]->children[0]->value);
        self::assertArrayHasKey('selected', $rendering->field->children[1]->attributes);
        $editable = (new WidgetDeriver())->derive($this->widget(new FormField('editable', FieldType::Choice, 262144, options:$field->options)), 'edit');
        self::assertSame('edit-options', $editable->field->attributes['list']);
        self::assertSame('datalist', $editable->nodes[1]->tag);
    }
    public function testRichTextIsTextAndNeverMarkupInsideInputOrTextarea(): void
    {
        $field = new FormField('name', FieldType::Text, 33554432, richText:'<body><p>Jane &amp; John</p></body>');
        $rendering = (new WidgetDeriver())->derive($this->widget($field), 'name');
        self::assertSame('Jane & John', $rendering->field->attributes['value']);
        self::assertSame([], $rendering->field->children);
        self::assertNotEmpty($rendering->diagnostics);
        $multiline = (new WidgetDeriver())->derive($this->widget(new FormField('notes', FieldType::Text, 4096, value:"a\nb", maximumLength:20)), 'notes');
        self::assertSame('textarea', $multiline->field->tag);
        self::assertSame("a\nb", $multiline->field->children[0]->value);
    }
    public function testFileInputsDoNotPrefillLocalPaths(): void
    {
        $rendering = (new WidgetDeriver())->derive($this->widget(new FormField('upload', FieldType::Text, 1048576, value:'C:/private.txt')), 'upload');
        self::assertSame('file', $rendering->field->attributes['type']);
        self::assertArrayNotHasKey('value', $rendering->field->attributes);
        self::assertNotEmpty($rendering->diagnostics);
    }
    public function testWidgetVisibilityHandlesZeroMixedNumbersAndOffPage(): void
    {
        $field = new FormField('value', FieldType::Text);
        $deriver = new WidgetDeriver();
        foreach ([
            new Widget($field), new Widget($field, page:1, rectangle:[0,0,20,20], annotationFlags:2),
            new Widget($field, page:1, rectangle:[0,0,0.0,20]),
            new Widget($field, page:1, rectangle:[201,0,220,20], pageBox:[0,0,200,200]),
        ] as $widget) {
            self::assertStringContainsString('display:none', $deriver->derive($widget, 'x')->field->attributes['style']);
        }
        self::assertStringNotContainsString('display:none', $deriver->derive(new Widget($field, page:1, rectangle:[20,20,0,0], pageBox:[0,0,200,200]), 'x')->field->attributes['style'] ?? '');
    }
    public function testReadOnlyChoiceUsesAnExplicitBridgeRatherThanInvalidReadonly(): void
    {
        $rendering = (new WidgetDeriver())->derive($this->widget(new FormField('choice', FieldType::Choice, 1)), 'choice');
        self::assertArrayNotHasKey('readonly', $rendering->field->attributes);
        self::assertSame('true', $rendering->field->attributes['aria-readonly']);
        self::assertSame('true', $rendering->field->attributes['data-pdf-readonly']);
        self::assertNotEmpty($rendering->diagnostics);
    }
    public function testReadonlyMetadataRespectsCheckboxComboboxAndListboxRoles(): void
    {
        foreach ([
            new FormField('checkbox', FieldType::Button, 1),
            new FormField('listbox', FieldType::Choice, 1),
            new FormField('combobox', FieldType::Choice, 131073),
            new FormField('multiple', FieldType::Choice, 2097153),
        ] as $source) {
            $rendering = (new WidgetDeriver())->derive($this->widget($source), $source->name);
            self::assertSame('true', $rendering->field->attributes['aria-readonly'], $source->name);
            self::assertSame('true', $rendering->field->attributes['data-pdf-readonly'], $source->name);
            self::assertArrayNotHasKey('readonly', $rendering->field->attributes, $source->name);
            self::assertArrayNotHasKey('disabled', $rendering->field->attributes, $source->name);
            self::assertStringContainsString('aria-readonly describes its PDF state', implode(' ', $rendering->diagnostics));
        }
    }
    public function testReadonlyUnsupportedRolesRetainStateWithoutChangingSubmission(): void
    {
        foreach ([
            new FormField('radio', FieldType::Button, 32769),
            new FormField('button', FieldType::Button, 65537),
            new FormField('submit', FieldType::Button, 65537, actionType: 'SubmitForm', actionTarget: 'https://example.test/send', submitFlags: 4),
            new FormField('reset', FieldType::Button, 65537, actionType: 'ResetForm'),
            new FormField('signature', FieldType::Signature, 1),
            new FormField('file', FieldType::Text, 1048577),
        ] as $source) {
            $rendering = (new WidgetDeriver())->derive($this->widget($source), $source->name);
            self::assertSame('true', $rendering->field->attributes['data-pdf-readonly'], $source->name);
            self::assertSame($source->name, $rendering->field->attributes['name']);
            self::assertArrayNotHasKey('aria-readonly', $rendering->field->attributes, $source->name);
            self::assertArrayNotHasKey('readonly', $rendering->field->attributes, $source->name);
            self::assertArrayNotHasKey('disabled', $rendering->field->attributes, $source->name);
            self::assertStringContainsString('role does not support aria-readonly', implode(' ', $rendering->diagnostics));
            if ($source->name === 'submit') {
                self::assertSame('submit', $rendering->field->attributes['type']);
                self::assertSame('https://example.test/send', $rendering->field->attributes['formaction']);
            }
        }
    }
    public function testNativeReadonlyAlsoRetainsRuntimeStateWithoutRedundantAria(): void
    {
        foreach ([
            new FormField('text', FieldType::Text, 1),
            new FormField('password', FieldType::Text, 8193),
            new FormField('multiline', FieldType::Text, 4097),
            new FormField('editable', FieldType::Choice, 393217),
        ] as $source) {
            $field = (new WidgetDeriver())->derive($this->widget($source), $source->name)->field;
            self::assertSame('', $field->attributes['readonly'], $source->name);
            self::assertSame('true', $field->attributes['data-pdf-readonly'], $source->name);
            self::assertArrayNotHasKey('aria-readonly', $field->attributes, $source->name);
            self::assertArrayNotHasKey('disabled', $field->attributes, $source->name);
        }
    }
    public function testWidgetColorsBorderAndRequiredText(): void
    {
        $rendering = (new WidgetDeriver())->derive(new Widget(new FormField('x', FieldType::Text, 3), page:1, rectangle:[0,0,20,20], borderColor:[0], backgroundColor:[1,1,1], borderWidth:3, borderStyle:'D', alignment:2), 'x');
        self::assertSame('', $rendering->field->attributes['readonly']);
        self::assertSame('', $rendering->field->attributes['required']);
        self::assertStringContainsString('border-color:rgb(0, 0, 0)', $rendering->field->attributes['style']);
        self::assertStringContainsString('border-width:4px', $rendering->field->attributes['style']);
    }
}
