<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

/** Adobe Glyph List Specification 2.9 §2: names describe Unicode scalars, not UTF-16. */
final readonly class GlyphNameDecoder
{
    public function __construct(private AdobeGlyphList $list = new AdobeGlyphList(), private int $maximumTextBytes = 33554432)
    {
        if ($maximumTextBytes < 1) {
            throw new \InvalidArgumentException('The glyph expansion limit must be positive.');
        }
    }

    public function decode(string $name, bool $zapfDingbats = false): string
    {
        if (strlen($name) > $this->maximumTextBytes) {
            throw new ReadingException('A glyph name exceeds the text limit.');
        }
        $result = '';
        foreach (explode('_', explode('.', $name, 2)[0]) as $component) {
            $value = $this->component($component, $zapfDingbats);
            if (strlen($value) > $this->maximumTextBytes - strlen($result)) {
                throw new ReadingException('A composite glyph name exceeds the text limit.');
            }
            $result .= $value;
        }
        return $result;
    }

    private function component(string $name, bool $zapfDingbats): string
    {
        $known = $this->list->character($name, $zapfDingbats);
        if ($known !== null) {
            return $known;
        }
        if (preg_match('/\Auni((?:[0-9A-F]{4})+)\z/D', $name, $match) === 1) {
            $text = '';
            foreach (str_split($match[1], 4) as $hex) {
                $scalar = (int) hexdec($hex);
                if ($scalar >= 0xd800 && $scalar <= 0xdfff) {
                    return '';
                }
                $text .= mb_chr($scalar, 'UTF-8');
            }
            return $text;
        }
        if (preg_match('/\Au([0-9A-F]{4,6})\z/D', $name, $match) === 1) {
            $scalar = (int) hexdec($match[1]);
            if ($scalar <= 0x10ffff && ($scalar < 0xd800 || $scalar > 0xdfff)) {
                return mb_chr($scalar, 'UTF-8');
            }
        }
        return '';
    }
}
