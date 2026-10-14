<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Document\Fonts\Encodings\EncodingVectors;
use PDF\Reading\EncodedStream;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Font character codes are distinct from PDF text strings and their BOM encodings. */
final class FontTextDecoder
{
    private ToUnicodeReader $unicode;
    /** @var array<int, array<int, string>> */
    private array $simple = [];
    private readonly GlyphNameDecoder $glyphs;
    public function __construct(private readonly SourceValues $values)
    {
        $this->unicode = new ToUnicodeReader($values->document, $values->budget);
        $this->glyphs = new GlyphNameDecoder(maximumTextBytes: $values->budget->limits->maximumTextBytes);
    }
    public function decode(DictionaryType $font, string $bytes): string
    {
        $toUnicode = $this->values->value($font, 'ToUnicode');
        if ($toUnicode instanceof EncodedStream) {
            return $this->unicode->read($toUnicode)->decode($bytes);
        }
        if ($this->values->name($font->get('Subtype')) === 'Type0') {
            throw new UnsupportedTextException('A composite font without ToUnicode requires an explicit CID character collection.');
        }
        $identity = spl_object_id($font);
        if (!isset($this->simple[$identity])) {
            $this->simple[$identity] = $this->simpleEncoding($font);
        }
        $result = '';
        for ($offset = 0, $length = strlen($bytes); $offset < $length; ++$offset) {
            $character = $this->simple[$identity][ord($bytes[$offset])] ?? null;
            if ($character === null) {
                throw new UnsupportedTextException('A simple-font character has no unambiguous Unicode mapping.');
            }
            $this->values->budget->consume('text', strlen($character));
            $result .= $character;
        }
        return $result;
    }
    /** @return array<int, string> */
    private function simpleEncoding(DictionaryType $font): array
    {
        $encoding = $this->values->value($font, 'Encoding');
        $base = $encoding instanceof NameType ? $encoding->value : ($encoding instanceof DictionaryType ? $this->values->name($encoding->get('BaseEncoding')) : null);
        $fontName = $this->values->name($font->get('BaseFont')) ?? '';
        $fontName = preg_replace('/\A[A-Z]{6}\+/', '', $fontName) ?? $fontName;
        $base ??= in_array($fontName, ['Symbol', 'ZapfDingbats'], true) ? $fontName : 'StandardEncoding';
        $kind = match ($base) {
            'WinAnsiEncoding' => 'WinAnsi', 'MacRomanEncoding' => 'MacRoman', 'StandardEncoding' => 'Standard', 'MacExpertEncoding' => 'MacExpert', 'Symbol' => 'Symbol', 'ZapfDingbats' => 'ZapfDingbats', default => throw new UnsupportedTextException('The font encoding requires an explicit decoder.')
        };
        $glyphs = EncodingVectors::MAPS[$kind];
        if ($encoding instanceof DictionaryType) {
            $differences = $this->values->value($encoding, 'Differences');
            if ($differences !== null && !$differences instanceof ArrayType) {
                throw new ReadingException('Font encoding Differences must be an array.');
            }
            $code = null;
            foreach ($differences instanceof ArrayType ? $differences->items : [] as $item) {
                $item = $this->values->document->resolve($item);
                if ($item instanceof IntegerType && $item->integer() >= 0 && $item->integer() <= 255) {
                    $code = $item->integer();
                } elseif ($item instanceof NameType && $code !== null && $code <= 255) {
                    $glyphs[$code++] = $item->value;
                } else {
                    throw new ReadingException('Font encoding Differences contains an invalid code or glyph.');
                }
            }
        }
        $result = [];
        foreach ($glyphs as $code => $glyph) {
            $this->values->budget->consume('mappings');
            $unicode = $this->glyphs->decode($glyph, $fontName === 'ZapfDingbats');
            if ($unicode !== '') {
                $this->values->budget->consume('retainedText', strlen($unicode));
                $result[$code] = $unicode;
            }
        }
        return $result;
    }
}
