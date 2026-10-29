<?php

declare(strict_types=1);

namespace PDF\Html\Tests;

use PDF\Html\PdfToHtml;
use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\ReadDocument;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Real LuaLaTeX output complements, rather than replaces, the small authored unit cases. */
final class LatexMathMlInteropTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function fixtures(): iterable
    {
        yield 'LaTeX MathML SE without MathML AF' => ['mathml-se', false];
        yield 'LaTeX MathML SE takes precedence over MathML AF' => ['mathml-se-af', true];
    }

    #[DataProvider('fixtures')]
    public function testRealLatexStructureKeepsInlineDisplayAndMatrixMath(string $name, bool $hasAssociatedMath): void
    {
        $root = __DIR__ . '/Fixtures/mathml-latex/';
        $provenanceBytes = file_get_contents($root . 'provenance.json');
        self::assertIsString($provenanceBytes);
        $provenance = json_decode($provenanceBytes, true, flags: JSON_THROW_ON_ERROR);
        $bytes = file_get_contents($root . $name . '.pdf');
        self::assertIsString($bytes);
        self::assertSame($provenance['fixtures'][$name]['pdf']['sha256'], hash('sha256', $bytes));
        self::assertSame($provenance['fixtures'][$name]['source']['sha256'], hash_file('sha256', $root . $name . '.tex'));
        self::assertTrue($provenance['fixtures'][$name]['independentCleanBuildsIdentical']);
        $file = new FileBytes($bytes);
        $read = (new DocumentReader())->read($file);
        $tree = $this->resolve($read, $read->catalog()->get('StructTreeRoot'));
        self::assertInstanceOf(DictionaryType::class, $tree);
        $elements = $this->elements($read, $tree->get('K'));
        $mathIds = [];
        $associatedCount = 0;
        foreach ($elements as $element) {
            $role = $element->get('S');
            if ($role instanceof NameType && $role->value === 'math') {
                $namespace = $this->resolve($read, $element->get('NS'));
                self::assertInstanceOf(DictionaryType::class, $namespace);
                $uri = $namespace->get('NS');
                self::assertInstanceOf(StringType::class, $uri);
                self::assertSame('http://www.w3.org/1998/Math/MathML', $uri->value);
                $id = $element->get('ID');
                self::assertInstanceOf(StringType::class, $id);
                $mathIds[] = $id->value;
            }
            $files = $element->get('AF');
            if (!$files instanceof ArrayType) {
                continue;
            }
            foreach ($files->items as $reference) {
                $specification = $read->resolve($reference);
                self::assertInstanceOf(DictionaryType::class, $specification);
                $embedded = $this->resolve($read, $specification->get('EF'));
                self::assertInstanceOf(DictionaryType::class, $embedded);
                $stream = $this->resolve($read, $embedded->get('F'));
                self::assertInstanceOf(EncodedStream::class, $stream);
                $subtype = $stream->dictionary->get('Subtype');
                if ($subtype instanceof NameType && $subtype->value === 'application/mathml+xml') {
                    ++$associatedCount;
                    self::assertEquals(new NameType('Supplement'), $specification->get('AFRelationship'));
                    $xml = new \DOMDocument();
                    self::assertTrue($xml->loadXML($read->streamData($stream), LIBXML_NONET));
                    self::assertFalse($xml->documentElement?->hasAttribute('id'));
                }
            }
        }
        self::assertCount(3, $mathIds);
        self::assertSame($hasAssociatedMath ? 3 : 0, $associatedCount);
        $html = (new PdfToHtml())->convert($file, $name . '.pdf')->html;
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($dom->loadHTML($html, LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $math = $dom->getElementsByTagName('math');
        self::assertCount(3, $math);
        foreach ($mathIds as $index => $id) {
            // The AF XML has no IDs: these original structure IDs make SE precedence observable.
            self::assertSame($id, $math->item($index)?->getAttribute('id'));
        }
        self::assertFalse($math->item(0)?->hasAttribute('display'));
        self::assertSame('block', $math->item(1)?->getAttribute('display'));
        self::assertSame('block', $math->item(2)?->getAttribute('display'));
        self::assertSame('𝑥2+12', $math->item(0)->textContent);
        self::assertSame('𝐸=𝑚𝑐2+12', $math->item(1)->textContent);
        self::assertSame('(1234)', $math->item(2)->textContent);
        self::assertCount(2, $dom->getElementsByTagName('msup'));
        self::assertCount(2, $dom->getElementsByTagName('mfrac'));
        self::assertCount(1, $dom->getElementsByTagName('mtable'));
        self::assertCount(2, $dom->getElementsByTagName('mtr'));
        self::assertCount(4, $dom->getElementsByTagName('mtd'));
        self::assertStringContainsString(' stays inside this sentence.', $html);
    }

    private function resolve(ReadDocument $read, ?BasicType $value): BasicType
    {
        self::assertNotNull($value);
        return $read->resolve($value);
    }

    /** @return list<DictionaryType> */
    private function elements(ReadDocument $read, ?BasicType $value): array
    {
        if ($value === null) {
            return [];
        }
        $value = $read->resolve($value);
        if ($value instanceof ArrayType) {
            $result = [];
            foreach ($value->items as $child) {
                array_push($result, ...$this->elements($read, $child));
            }
            return $result;
        }
        if (!$value instanceof DictionaryType || !$value->get('S') instanceof NameType) {
            return [];
        }
        return [$value, ...$this->elements($read, $value->get('K'))];
    }
}
