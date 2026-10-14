<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Script;

use PDF\Html\Forms\FieldType;
use PDF\Html\Model\FormField;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\Widget;
use PDF\Html\Script\ScriptDeriver;
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
}
