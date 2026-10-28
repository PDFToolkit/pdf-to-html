<?php

declare(strict_types=1);

namespace PDF\Html\Tests;

use PDF\Html\PdfToHtml;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\ImageCodecs\CodecRequest;
use PDF\Reading\ImageCodecs\CodecUnavailable;
use PDF\Reading\ImageCodecs\DecodedImage;
use PDF\Reading\ImageCodecs\Decoder;
use PHPUnit\Framework\TestCase;

final class CodecImageReadingTest extends TestCase
{
    public function testUnavailableImageDecoderNeverDropsImagesOrSilentlyPassesUnsafeJpegs(): void
    {
        $decoder = new class () implements Decoder {
            public function decode(CodecRequest $request): DecodedImage
            {
                throw new CodecUnavailable('Test application deliberately provides no image codec.');
            }
        };
        $pdf = (string) file_get_contents(__DIR__ . '/Fixtures/codec-images.pdf');
        $result = (new PdfToHtml(reader: new DocumentReader(imageDecoder: $decoder)))->convert(new FileBytes($pdf), 'codec-images.pdf');
        $document = new \DOMDocument();
        @$document->loadHTML($result->html);
        $images = $document->getElementsByTagName('img');
        self::assertCount(23, $images);
        foreach ($images as $image) {
            if (in_array($image->getAttribute('alt'), ['jpeg-native', 'jpeg-adobe-zero'], true)) {
                self::assertStringStartsWith('data:image/jpeg;base64,', $image->getAttribute('src'));
            } else {
                self::assertStringStartsWith('data:image/gif;base64,', $image->getAttribute('src'));
            }
            self::assertGreaterThan(0, (int) $image->getAttribute('width'));
            self::assertGreaterThan(0, (int) $image->getAttribute('height'));
        }
        self::assertCount(21, array_filter($result->diagnostics, static fn ($diagnostic): bool => $diagnostic->code === 'image-placeholder'));
        self::assertCount(21, array_filter($result->diagnostics, static fn ($diagnostic): bool => $diagnostic->code === 'source-interpretation' && str_contains($diagnostic->message, 'deliberately provides no image codec')));
    }
}
