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
    /** @var array<string, string> */
    private array $glyphs = [];
    public function __construct(private readonly SourceValues $values)
    {
        $this->unicode = new ToUnicodeReader($values->document, $values->budget);
        foreach (EncodingVectors::MAPS['WinAnsi'] as $code => $glyph) {
            $character = @iconv('Windows-1252', 'UTF-8', chr($code));
            if ($character !== false) {
                $this->glyphs[$glyph] ??= $character;
            }
        }
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
            $unicode = $this->glyph($glyph);
            if ($unicode !== null) {
                $this->values->budget->consume('retainedText', strlen($unicode));
                $result[$code] = $unicode;
            }
        }
        return $result;
    }
    private function glyph(string $name): ?string
    {
        $name = explode('.', $name, 2)[0];
        if (str_contains($name, '_')) {
            $text = '';
            foreach (explode('_', $name) as $part) {
                $value = $this->glyph($part);
                if ($value === null) {
                    return null;
                }
                if (strlen($text) + strlen($value) > $this->values->budget->limits->maximumTextBytes) {
                    throw new ReadingException('A composite glyph name exceeds the retained text limit.');
                }
                $text .= $value;
            }
            return $text;
        }
        if (preg_match('/\Auni([0-9A-Fa-f]{4}(?:[0-9A-Fa-f]{4})*)\z/', $name, $match)) {
            $bytes = hex2bin($match[1]);
            if ($bytes === false) {
                throw new ReadingException('A Unicode glyph name is malformed.');
            }
            return (new \PDF\Document\Fonts\CMaps\UnicodeSequence(new \PDF\Syntax\Object\StringObject\HexadecimalString($bytes)))->utf8();
        }
        if (preg_match('/\Au([0-9A-Fa-f]{4,6})\z/', $name, $match)) {
            $scalar = hexdec($match[1]);
            if (!is_int($scalar) || $scalar > 0x10ffff || ($scalar >= 0xd800 && $scalar <= 0xdfff)) {
                throw new ReadingException('A glyph name contains an invalid Unicode scalar.');
            }
            return mb_chr($scalar, 'UTF-8');
        }
        if (isset($this->glyphs[$name])) {
            return $this->glyphs[$name];
        }
        return match ($name) {
            'fi' => 'ﬁ', 'fl' => 'ﬂ', 'ff' => 'ﬀ', 'ffi' => 'ﬃ', 'ffl' => 'ﬄ', 'dotlessi' => 'ı', 'Lslash' => 'Ł', 'lslash' => 'ł', 'OE' => 'Œ', 'oe' => 'œ', 'fraction' => '⁄', 'minus' => '−', default => null
        };
    }
}
