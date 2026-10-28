<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Reading\ImageCodecs\ComponentPlane;
use PDF\Reading\ImageCodecs\DecodedImage;
use PDF\Reading\UnsupportedPdfFeature;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;

/** Preserve exact normalised samples; never truncate a codec plane to fit a PNG. */
final readonly class DecodedImagePng
{
    public function __construct(private SourceValues $values)
    {
    }

    public function encode(DecodedImage $image, DictionaryType $dictionary, bool $jpx, int $width, int $height, ?DictionaryType $resources = null): string
    {
        if ($image->width !== $width || $image->height !== $height) {
            throw new ReadingException('Decoded image dimensions disagree with the PDF image dictionary.');
        }
        $color = (new ImageColorReader($this->values))->read($dictionary, $image, $jpx, $resources);
        $planes = [];
        $alphas = [];
        foreach ($image->components as $index => $plane) {
            if ($plane->alpha === 'none') {
                $planes[] = $plane;
            } else {
                $alphas[$index] = $plane;
            }
        }
        if (count($planes) !== $color->components) {
            throw new ReadingException('Decoded colour channels disagree with the selected PDF colour space.');
        }
        $mask = $jpx ? $this->maskMode($dictionary) : 0;
        $alpha = null;
        if ($mask !== 0) {
            if (count($alphas) !== 1) {
                throw new ReadingException('SMaskInData requires exactly one opacity channel.');
            }
            $alpha = reset($alphas);
            if ($alpha->alpha !== ($mask === 2 ? 'associated' : 'unassociated')) {
                throw new ReadingException('SMaskInData disagrees with the JPX opacity channel type.');
            }
            $associations = [];
            foreach ($image->channelDefinitions as $definition) {
                if ($definition->outputChannel === key($alphas) && in_array($definition->type, [1, 2], true)) {
                    $associations[] = $definition->association;
                }
            }
            $associations = array_values(array_unique($associations));
            sort($associations);
            if ($associations !== [0] && $associations !== range(1, $color->components)) {
                throw new ReadingException('The JPX opacity channel must apply to every colour channel.');
            }
        }
        $inverted = $this->decode($dictionary, $jpx, $color->components);
        if ($mask === 2 && in_array(true, $inverted, true)) {
            throw new UnsupportedPdfFeature('Premultiplied JPX opacity with a non-default Decode requires explicit rendering.');
        }
        $all = $alpha === null ? $planes : [...$planes, $alpha];
        $bits = 1;
        foreach ($all as $plane) {
            if ($plane->signed || !in_array($plane->precision, [1, 2, 4, 8, 16], true)) {
                throw new UnsupportedPdfFeature('The codec sample precision or signedness cannot be retained exactly in this PNG representation.');
            }
            if ($plane->width !== $width || $plane->height !== $height || $plane->separationX !== 1 || $plane->separationY !== 1
                || $plane->originX !== $image->originX || $plane->originY !== $image->originY) {
                throw new UnsupportedPdfFeature('Distinct codec sample grids require an explicit resampling capability.');
            }
            $bits = max($bits, $plane->precision);
        }
        if ($color->components === 3 || $alpha !== null) {
            $bits = max(8, $bits);
        }
        if (!$jpx) {
            $declared = $this->values->value($dictionary, 'BitsPerComponent');
            if (!$declared instanceof IntegerType || $declared->integer() !== $planes[0]->precision) {
                throw new ReadingException('BitsPerComponent disagrees with the decoded image samples.');
            }
        }
        $channels = count($all);
        if ($width > intdiv(PHP_INT_MAX - 7, $channels * $bits) || $height > intdiv(PHP_INT_MAX, $width)) {
            throw new ReadingException('Decoded image dimensions exceed the sample buffer budget.');
        }
        $rowBytes = intdiv($width * $channels * $bits + 7, 8);
        if ($rowBytes > intdiv($this->values->budget->remaining('content'), $height)) {
            throw new ReadingException('Decoded image samples exceed the cumulative source content budget.');
        }
        if ($width * $height > intdiv($this->values->budget->remaining('instructions'), $channels)) {
            throw new ReadingException('Decoded image samples exceed the cumulative source instructions budget.');
        }
        $this->values->budget->consume('content', $rowBytes * $height);
        $this->values->budget->consume('instructions', $width * $height * $channels);
        $pixels = '';
        $maximum = (1 << $bits) - 1;
        for ($y = 0; $y < $height; ++$y) {
            $packed = $available = 0;
            for ($x = 0; $x < $width; ++$x) {
                $opacity = $alpha === null ? $maximum : $this->scaled($alpha, $x, $y, $maximum);
                foreach ($all as $index => $plane) {
                    $value = $this->scaled($plane, $x, $y, $maximum);
                    if ($index < $color->components) {
                        if ($mask === 2) {
                            if ($opacity === 0) {
                                $value = 0;
                            } elseif ($value > $opacity || ($value * $maximum) % $opacity !== 0) {
                                throw new UnsupportedPdfFeature('Unpremultiplying JPX samples would require rounding; an explicit renderer is required.');
                            } else {
                                $value = intdiv($value * $maximum, $opacity);
                            }
                        }
                        if ($inverted[$index]) {
                            $value = $maximum - $value;
                        }
                    }
                    if ($bits === 16) {
                        $pixels .= pack('n', $value);
                    } elseif ($bits === 8) {
                        $pixels .= chr($value);
                    } else {
                        $packed = ($packed << $bits) | $value;
                        $available += $bits;
                        if ($available === 8) {
                            $pixels .= chr($packed);
                            $packed = $available = 0;
                        }
                    }
                }
            }
            if ($available !== 0) {
                $pixels .= chr($packed << (8 - $available));
            }
        }
        return (new PngSampleEncoder($this->values->budget))->encode($pixels, $width, $height, $color->components, $bits, $alpha !== null, $color->iccProfile);
    }

    private function scaled(ComponentPlane $plane, int $x, int $y, int $maximum): int
    {
        return $plane->sample($x, $y) * intdiv($maximum, (1 << $plane->precision) - 1);
    }

    private function maskMode(DictionaryType $dictionary): int
    {
        $mode = $this->values->value($dictionary, 'SMaskInData');
        if ($mode === null || $mode instanceof NullType) {
            return 0;
        }
        if (!$mode instanceof IntegerType || !in_array($mode->integer(), [0, 1, 2], true)) {
            throw new ReadingException('SMaskInData must be zero, one or two.');
        }
        return $mode->integer();
    }

    /** @return list<bool> */
    private function decode(DictionaryType $dictionary, bool $jpx, int $components): array
    {
        $space = $this->values->value($dictionary, 'ColorSpace');
        $decode = $this->values->value($dictionary, 'Decode');
        if (($jpx && ($space === null || $space instanceof NullType)) || $decode === null || $decode instanceof NullType) {
            return array_fill(0, $components, false);
        }
        if (!$decode instanceof ArrayType || count($decode) !== 2 * $components) {
            throw new ReadingException('An image Decode array requires two numbers per colour channel.');
        }
        $result = [];
        for ($index = 0; $index < $components; ++$index) {
            $low = $this->values->document->resolve($decode->items[2 * $index]);
            $high = $this->values->document->resolve($decode->items[2 * $index + 1]);
            if (!$low instanceof NumericType || !$high instanceof NumericType) {
                throw new ReadingException('An image Decode array must contain numbers.');
            }
            if (!in_array([(float) $low->value, (float) $high->value], [[0.0, 1.0], [1.0, 0.0]], true)) {
                throw new UnsupportedPdfFeature('This image Decode mapping requires an explicit colour rendering capability.');
            }
            $result[] = (float) $low->value === 1.0;
        }
        return $result;
    }
}
