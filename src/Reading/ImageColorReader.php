<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Reading\EncodedStream;
use PDF\Reading\ImageCodecs\DecodedImage;
use PDF\Reading\UnsupportedPdfFeature;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;

/** ISO 32000-2 7.4.9: an explicit PDF colour space overrides JPX colour metadata. */
final readonly class ImageColorReader
{
    public function __construct(private SourceValues $values)
    {
    }

    public function read(DictionaryType $dictionary, DecodedImage $image, bool $jpx, ?DictionaryType $resources): PngImageColor
    {
        $space = $this->values->value($dictionary, 'ColorSpace');
        if ($space === null || $space instanceof NullType) {
            if (!$jpx) {
                throw new ReadingException('A non-JPX image requires a PDF ColorSpace.');
            }
            $count = count(array_filter($image->components, static fn ($plane): bool => $plane->alpha === 'none'));
            if ($image->color->iccProfile !== null) {
                return $this->icc($image->color->iccProfile, $count);
            }
            // Other JPX enumerations include distinct transfer functions and chromaticities.
            if (($image->color->space === 'RGB' && in_array($image->color->enumeratedSpace, [null, 16], true))
                || ($image->color->space === 'Gray' && in_array($image->color->enumeratedSpace, [null, 17], true))) {
                return new PngImageColor($image->color->space === 'Gray' ? 1 : 3);
            }
            throw new UnsupportedPdfFeature('The JPX colour interpretation requires an explicit colour rendering capability.');
        }
        if ($space instanceof NameType && !in_array($space->value, ['DeviceGray', 'DeviceRGB', 'DeviceCMYK'], true) && $resources !== null) {
            $colors = $this->values->dictionary($resources->get('ColorSpace'));
            $space = $colors === null ? $space : ($this->values->value($colors, $space->value) ?? $space);
        }
        if ($space instanceof NameType && in_array($space->value, ['DeviceGray', 'DeviceRGB'], true)) {
            return new PngImageColor($space->value === 'DeviceGray' ? 1 : 3);
        }
        if ($space instanceof ArrayType && count($space) === 2 && $this->values->name($space->items[0]) === 'ICCBased') {
            $profile = $this->values->document->resolve($space->items[1]);
            if (!$profile instanceof EncodedStream) {
                throw new ReadingException('An ICCBased colour space requires a profile stream.');
            }
            $n = $this->values->value($profile->dictionary(), 'N');
            if (!$n instanceof IntegerType || !in_array($n->integer(), [1, 3, 4], true)) {
                throw new ReadingException('An ICCBased profile requires one, three or four components.');
            }
            $range = $this->values->value($profile->dictionary(), 'Range');
            if ($range !== null && !$range instanceof NullType) {
                if (!$range instanceof ArrayType || count($range) !== 2 * $n->integer()) {
                    throw new ReadingException('An ICCBased Range must contain two numbers per component.');
                }
                foreach ($range->items as $index => $item) {
                    $item = $this->values->document->resolve($item);
                    if (!$item instanceof NumericType || (float) $item->value !== (float) ($index % 2)) {
                        throw new UnsupportedPdfFeature('Non-default ICC component ranges require explicit colour rendering.');
                    }
                }
            }
            return $this->icc($this->values->document->streamData($profile), $n->integer());
        }
        throw new UnsupportedPdfFeature('The PDF image colour space requires an explicit colour rendering capability.');
    }

    private function icc(string $bytes, int $components): PngImageColor
    {
        if (!in_array($components, [1, 3], true)) {
            throw new UnsupportedPdfFeature('PNG cannot retain this ICC colour channel count.');
        }
        $this->values->budget->consume('content', strlen($bytes));
        (new PngIccProfile($this->values->budget))->validate($bytes, $components);
        return new PngImageColor($components, $bytes);
    }
}
