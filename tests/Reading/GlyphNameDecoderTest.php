<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Reading;

use PDF\Html\Reading\AdobeGlyphList;
use PDF\Html\Reading\FontTextDecoder;
use PDF\Html\Reading\GlyphNameDecoder;
use PDF\Html\Reading\ReadingBudget;
use PDF\Html\Reading\ReadingException;
use PDF\Html\Reading\ReadingLimits;
use PDF\Html\Reading\SourceValues;
use PDF\Html\Reading\UnsupportedTextException;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GlyphNameDecoderTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function names(): iterable
    {
        yield 'Adobe example with suffix and supplementary scalar' => ['Lcommaaccent_uni20AC0308_u1040C.alternate', "\u{13B}\u{20AC}\u{308}\u{1040C}", false];
        yield 'legacy Hebrew sequence' => ['dalethatafpatah', "\u{5D3}\u{5B2}", false];
        yield 'expert small cap PUA is preserved' => ['Asmall', "\u{F761}", false];
        yield 'Symbol alpha' => ['alpha', 'α', false];
        yield 'Zapf glyph with font context' => ['a1', '✁', true];
        yield 'Zapf glyph outside font context' => ['a1', '', false];
        yield 'unknown components follow AGL empty mapping' => ['A_unknown_B', 'AB', false];
        yield 'suffix starts at first period' => ['T.swash_h', 'T', false];
        yield 'ligature' => ['f_f_i', 'ffi', false];
        yield 'lowercase uni is not a scalar name' => ['uni20ac', '', false];
        yield 'surrogate pair is not BMP scalars' => ['uniD801DC0C', '', false];
        yield 'isolated surrogate' => ['uD800', '', false];
        yield 'out of Unicode range' => ['u110000', '', false];
        yield 'noncharacter is still a scalar' => ['u10FFFF', "\u{10FFFF}", false];
        yield 'notdef' => ['.notdef', '', false];
    }

    #[DataProvider('names')]
    public function testAdobeGlyphNameAlgorithm(string $name, string $expected, bool $zapf): void
    {
        self::assertSame($expected, (new GlyphNameDecoder())->decode($name, $zapf));
    }

    public function testAllPinnedAdobeRecordsMatchTheirUnicodeScalars(): void
    {
        $list = new AdobeGlyphList();
        foreach (['glyphlist.txt' => false, 'zapfdingbats.txt' => true] as $filename => $zapf) {
            $lines = file(dirname(__DIR__, 2).'/resources/glyph-lists/'.$filename, FILE_IGNORE_NEW_LINES);
            self::assertIsArray($lines);
            foreach ($lines as $line) {
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                [$name, $hex] = explode(';', $line);
                $expected = implode('', array_map(static fn (string $scalar): string => mb_chr((int) hexdec($scalar), 'UTF-8'), explode(' ', $hex)));
                self::assertSame($expected, $list->character($name, $zapf), $name);
            }
        }
    }

    /** @return iterable<string, array{string, ?string, string, string}> */
    public static function fonts(): iterable
    {
        yield 'Standard fi' => ['Helvetica', 'StandardEncoding', chr(174), 'ﬁ'];
        yield 'MacRoman accented' => ['Helvetica', 'MacRomanEncoding', chr(128), 'Ä'];
        yield 'MacExpert PUA' => ['Expert', 'MacExpertEncoding', chr(97), "\u{F761}"];
        yield 'Symbol default' => ['Symbol', null, 'a', 'α'];
        yield 'subset Symbol default' => ['ABCDEF+Symbol', null, 'ab', 'αβ'];
        yield 'Dingbats default' => ['ZapfDingbats', null, '!', '✁'];
        yield 'subset Dingbats default' => ['ABCDEF+ZapfDingbats', null, '!', '✁'];
        yield 'WinAnsi euro' => ['Helvetica', 'WinAnsiEncoding', chr(128), '€'];
    }

    #[DataProvider('fonts')]
    public function testSimpleFontVectorsUseCompleteGlyphList(string $baseFont, ?string $encoding, string $bytes, string $expected): void
    {
        $entries = [new Entry(new NameType('BaseFont'), new NameType($baseFont)), new Entry(new NameType('Subtype'), new NameType('Type1'))];
        if ($encoding !== null) {
            $entries[] = new Entry(new NameType('Encoding'), new NameType($encoding));
        }
        self::assertSame($expected, $this->decoder()->decode(new DictionaryType(...$entries), $bytes));
    }

    public function testDifferencesMayDescribeMultiScalarTextAndSupplementaryCharacters(): void
    {
        $font = new DictionaryType(new Entry(new NameType('Encoding'), new DictionaryType(
            new Entry(new NameType('Differences'), new ArrayType(new IntegerType(65), new NameType('Lcommaaccent_uni20AC0308_u1040C.alternate')))
        )));
        self::assertSame("\u{13B}\u{20AC}\u{308}\u{1040C}", $this->decoder()->decode($font, 'A'));
    }

    public function testEntirelyUnmappedGlyphIsAnExplicitVisualFallback(): void
    {
        $font = new DictionaryType(new Entry(new NameType('Encoding'), new DictionaryType(
            new Entry(new NameType('Differences'), new ArrayType(new IntegerType(65), new NameType('uniD801DC0C')))
        )));
        $this->expectException(UnsupportedTextException::class);
        $this->decoder()->decode($font, 'A');
    }

    public function testGlyphExpansionHasAnExplicitBudget(): void
    {
        $this->expectException(ReadingException::class);
        (new GlyphNameDecoder(maximumTextBytes: 5))->decode('A_B_C_D');
    }

    private function decoder(): FontTextDecoder
    {
        $bytes = file_get_contents(dirname(__DIR__).'/Fixtures/tagged.pdf');
        self::assertIsString($bytes);
        return new FontTextDecoder(new SourceValues((new DocumentReader())->read(new FileBytes($bytes)), new ReadingBudget(new ReadingLimits())));
    }
}
