<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Forms;

use PDF\Html\Forms\FieldSelection;
use PDF\Html\Forms\FieldType;
use PDF\Html\Forms\FormActionPolicy;
use PDF\Html\Forms\ResetFormAction;
use PDF\Html\Forms\SubmitFormAction;
use PDF\Html\Forms\WidgetDeriver;
use PDF\Html\Model\FormField;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\Widget;
use PDF\Html\Script\ScriptDeriver;
use PHPUnit\Framework\TestCase;

final class FormActionsTest extends TestCase
{
    public function testFieldSelectionIncludesGroupsWithoutMatchingSimilarPrefixes(): void
    {
        $selection = new FieldSelection(['Person']);
        self::assertTrue($selection->contains('Person'));
        self::assertTrue($selection->contains('Person.Address.City'));
        self::assertFalse($selection->contains('Personality.Name'));
        $excluded = new FieldSelection(['Person'], true);
        self::assertFalse($excluded->contains('Person.Name'));
        self::assertTrue($excluded->contains('Personality.Name'));
    }

    public function testAbsentFieldsAndEmptyFieldsKeepTheirDifferentPdfMeanings(): void
    {
        self::assertTrue((new FieldSelection(null, true))->contains('Any'));
        self::assertFalse((new FieldSelection([]))->contains('Any'));
        self::assertTrue((new FieldSelection([], true))->contains('Any'));
    }

    public function testResetActionNeverSilentlyUsesNativeInitialValuesInTheSafeProfile(): void
    {
        $field = new FormField('Reset', FieldType::Button, 65536, resetAction: new ResetFormAction(['Person']));
        $safe = (new WidgetDeriver())->derive(new Widget($field), 'reset');
        self::assertSame('button', $safe->field->attributes['type']);
        self::assertStringContainsString('/DV', implode(' ', $safe->diagnostics));
        $enabled = (new WidgetDeriver(formActions: new FormActionPolicy(true)))->derive(new Widget($field), 'reset');
        self::assertSame('reset', $enabled->field->attributes['type']);
    }

    public function testSubmissionNeedsExplicitPermissionAndSupportedHtmlFlags(): void
    {
        $source = new FormField('Send', FieldType::Button, 65536, submitAction: new SubmitFormAction('https://example.test/submit', 12, ['Person']));
        $safe = (new WidgetDeriver())->derive(new Widget($source), 'submit');
        self::assertSame('button', $safe->field->attributes['type']);
        self::assertArrayNotHasKey('formaction', $safe->field->attributes);
        self::assertNotEmpty($safe->diagnostics);
        $enabled = (new WidgetDeriver(formActions: new FormActionPolicy(allowSubmit: true)))->derive(new Widget($source), 'submit');
        self::assertSame('submit', $enabled->field->attributes['type']);
        self::assertSame('get', $enabled->field->attributes['formmethod']);
        self::assertSame('', $enabled->field->attributes['formnovalidate']);
        foreach ([0, 36, 260, 516] as $flags) {
            $unsupported = new FormField('Send', FieldType::Button, 65536, submitAction: new SubmitFormAction('https://example.test/submit', $flags));
            $rendering = (new WidgetDeriver(formActions: new FormActionPolicy(allowSubmit: true)))->derive(new Widget($unsupported), 'submit');
            self::assertSame('button', $rendering->field->attributes['type']);
            self::assertNotEmpty($rendering->diagnostics);
        }
    }

    public function testResetOnlyRuntimeNeverIncludesPdfScriptsOrSubmissionDestinations(): void
    {
        $field = new FormField('Name', FieldType::Text, value: 'Current', actions: ['V' => 'throw new Error("field-source-marker");'], defaultValue: 'Default');
        $reset = new FormField('Reset', FieldType::Button, 65536, resetAction: new ResetFormAction(['Name']));
        $submit = new FormField('Submit', FieldType::Button, 65536, submitAction: new SubmitFormAction('javascript:submission-source-marker'));
        $document = new TaggedDocument('safe.pdf', [], documentScripts: ['throw new Error("document-source-marker");'], pageScripts: [1 => ['O' => 'throw new Error("page-source-marker");']]);
        $runtime = (new ScriptDeriver(false, new FormActionPolicy(true)))->derive($document, [new Widget($field, actions: ['U' => 'throw new Error("widget-source-marker");']), new Widget($reset), new Widget($submit)]);
        self::assertNotNull($runtime);
        foreach (['field-source-marker', 'document-source-marker', 'page-source-marker', 'widget-source-marker', 'submission-source-marker', 'javascript:', 'fetch(', 'XMLHttpRequest', 'new Function', 'eval('] as $source) {
            self::assertStringNotContainsString($source, $runtime);
        }
        self::assertStringContainsString('Default', $runtime);
    }

    public function testRequiredOnButtonProducesADiagnosticRatherThanAnInvalidAttribute(): void
    {
        $rendering = (new WidgetDeriver())->derive(new Widget(new FormField('Button', FieldType::Button, 65538)), 'button');
        self::assertArrayNotHasKey('required', $rendering->field->attributes);
        self::assertStringContainsString('no required attribute', implode(' ', $rendering->diagnostics));
    }

    public function testFileDefaultsAndMaximumLengthHaveExplicitBrowserAdaptations(): void
    {
        $source = new FormField('File', FieldType::Text, 1048576, maximumLength: 20, defaultValue: 'C:/private/default.txt');
        $rendering = (new WidgetDeriver())->derive(new Widget($source), 'file');
        self::assertArrayNotHasKey('value', $rendering->field->attributes);
        self::assertArrayNotHasKey('maxlength', $rendering->field->attributes);
        self::assertStringContainsString('cannot restore a PDF FileSelect /DV path', implode(' ', $rendering->diagnostics));
        self::assertStringContainsString('no maxlength attribute', implode(' ', $rendering->diagnostics));
    }
}
