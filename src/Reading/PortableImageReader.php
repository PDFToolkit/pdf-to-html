<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Html\Model\GraphicContent;
use PDF\Html\Model\GraphicProgram;
use PDF\Html\Model\ImageContent;
use PDF\Reading\EncodedStream;
use PDF\Reading\UnsupportedPdfFeature;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;

/** Document-scoped bridge between PDF image semantics and exact portable browser assets. */
final readonly class PortableImageReader
{
    public function __construct(private SourceValues $values)
    {
    }

    public function read(EncodedStream $stream, int $page, float $logicalWidth, float $logicalHeight, ?GraphicProgram $source): ImageContent|GraphicContent
    {
        $dictionary = $stream->dictionary();
        $pixelWidth = $this->values->value($dictionary, 'Width');
        $pixelHeight = $this->values->value($dictionary, 'Height');
        if (!$pixelWidth instanceof IntegerType || !$pixelHeight instanceof IntegerType || $pixelWidth->integer() <= 0 || $pixelHeight->integer() <= 0) {
            throw new ReadingException('An image requires positive integer sample dimensions.');
        }
        $width = $pixelWidth->integer();
        $height = $pixelHeight->integer();
        foreach (['OC', 'Alternates'] as $context) {
            if ($this->present($dictionary, $context)) {
                return new GraphicContent('unsupported-image-context', 'Image visibility and alternate selection require the explicit rendering capability; original resources and optional-content configuration are retained.', $logicalWidth, $logicalHeight, $page, $source);
            }
        }
        try {
            $mask = $this->values->value($dictionary, 'ImageMask');
            if ($mask instanceof BooleanType && $mask->value) {
                throw new UnsupportedPdfFeature('Stencil image colours require the explicit graphics rendering capability.');
            }
            if ($this->present($dictionary, 'SMask') || $this->present($dictionary, 'Mask')) {
                throw new UnsupportedPdfFeature('External image masks require the explicit graphics rendering capability.');
            }
            $external = $this->present($dictionary, 'F');
            $filter = $this->values->value($dictionary, $external ? 'FFilter' : 'Filter');
            $single = true;
            if ($filter instanceof ArrayType) {
                $single = count($filter) === 1;
                $filter = $filter->items === [] ? null : $this->values->document->resolve($filter->items[array_key_last($filter->items)]);
            }
            $codec = $filter instanceof NameType && in_array($filter->value, ['DCTDecode', 'JPXDecode', 'JBIG2Decode'], true);
            $color = $this->values->name($dictionary->get('ColorSpace'));
            $bits = $this->values->integer($dictionary->get('BitsPerComponent'), 8);
            if ($codec && $filter->value === 'DCTDecode' && !$external && $single && $bits === 8 && $this->values->value($dictionary, 'BitsPerComponent') instanceof IntegerType && !$this->present($dictionary, 'Decode')
                && in_array($color, ['DeviceGray', 'DeviceRGB'], true)) {
                $bytes = $stream->encodedBytes();
                if ((new JpegBrowserCompatibility($this->values->budget))->matches($bytes, $width, $height, $color === 'DeviceGray' ? 1 : 3, $this->colorTransform($dictionary))) {
                    $this->values->budget->consume('assets', strlen($bytes));
                    return new ImageContent($bytes, 'image/jpeg', $logicalWidth, $logicalHeight, $page, $source);
                }
            }
            if ($codec) {
                $image = $this->values->document->imageData($stream);
                $bytes = (new DecodedImagePng($this->values))->encode($image, $dictionary, $filter->value === 'JPXDecode', $width, $height, $source?->resources);
                return new ImageContent($bytes, 'image/png', $logicalWidth, $logicalHeight, $page, $source);
            }
            $pngDepth = $color === 'DeviceGray' && in_array($bits, [1, 2, 4, 8, 16], true) || $color === 'DeviceRGB' && in_array($bits, [8, 16], true);
            if ($pngDepth && !$this->present($dictionary, 'Decode') && $width <= 65535 && $height <= 65535) {
                $pixels = $this->values->document->streamData($stream);
                $bytes = (new PngSampleEncoder($this->values->budget))->encode($pixels, $width, $height, $color === 'DeviceRGB' ? 3 : 1, $bits);
                return new ImageContent($bytes, 'image/png', $logicalWidth, $logicalHeight, $page, $source);
            }
            throw new UnsupportedPdfFeature('Image conversion requires an explicit decoder for filters, masks, color spaces or sample depths.');
        } catch (UnsupportedPdfFeature $exception) {
            if (isset($codec) && $codec) {
                $this->values->budget->diagnostic($exception->getMessage());
            }
            return new GraphicContent('unsupported-image', $exception->getMessage(), $logicalWidth, $logicalHeight, $page, $source);
        }
    }

    private function present(DictionaryType $dictionary, string $key): bool
    {
        $value = $this->values->value($dictionary, $key);
        return $value !== null && !$value instanceof NullType;
    }

    private function colorTransform(DictionaryType $dictionary): ?int
    {
        $parameters = $this->values->value($dictionary, 'DecodeParms');
        if ($parameters instanceof ArrayType && count($parameters) === 1) {
            $parameters = $this->values->document->resolve($parameters->items[0]);
        }
        if ($parameters === null || $parameters instanceof NullType) {
            return null;
        }
        if (!$parameters instanceof DictionaryType) {
            throw new ReadingException('JPEG DecodeParms must be a dictionary or a matching array.');
        }
        $transform = $this->values->value($parameters, 'ColorTransform');
        if ($transform === null || $transform instanceof NullType) {
            return null;
        }
        if (!$transform instanceof IntegerType || !in_array($transform->integer(), [0, 1], true)) {
            throw new ReadingException('JPEG ColorTransform must be zero or one.');
        }
        return $transform->integer();
    }
}
