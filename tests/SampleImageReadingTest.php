<?php

declare(strict_types=1);

namespace PDF\Html\Tests;

use PDF\Html\PdfToHtml;
use PDF\Html\Reading\PngSampleEncoder;
use PDF\Html\Reading\ReadingBudget;
use PDF\Html\Reading\ReadingException;
use PDF\Html\Reading\ReadingLimits;
use PDF\Reading\FileBytes;
use PHPUnit\Framework\TestCase;

final class SampleImageReadingTest extends TestCase
{
    public function testIndependentInlineAndXobjectImagesRetainEverySampleAndBitDepth(): void
    {
        $manifest = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/sample-depths.json'), true, flags: JSON_THROW_ON_ERROR);
        $pdf = (string) file_get_contents(__DIR__ . '/Fixtures/sample-depths.pdf');
        self::assertSame($manifest['sha256'], hash('sha256', $pdf));
        $result = (new PdfToHtml())->convert(new FileBytes($pdf), 'sample-depths.pdf');
        $assets = [];
        foreach ($result->assets as $asset) {
            $assets[$asset->filename] = $asset->bytes;
        }
        $html = new \DOMDocument();
        @$html->loadHTML($result->html);
        $images = $html->getElementsByTagName('img');
        self::assertCount(17, $images);
        self::assertCount(3, array_filter($result->diagnostics, static fn ($item): bool => $item->code === 'image-placeholder'));
        foreach ($manifest['cases'] as $index => $case) {
            $image = $images->item($index);
            self::assertSame($case['name'], $image->getAttribute('alt'));
            self::assertSame('17', $image->getAttribute('width'));
            self::assertSame('7', $image->getAttribute('height'));
            if ($case['fallback'] !== null) {
                continue;
            }
            $source = $image->getAttribute('src');
            $png = str_starts_with($source, 'data:image/png;base64,') ? base64_decode(substr($source, 22), true) : ($assets[$source] ?? null);
            self::assertIsString($png);
            self::assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
            $compressed = '';
            for ($offset = 8; $offset < strlen($png); $offset += 12 + $length) {
                $length = unpack('N', $png, $offset)[1];
                $type = substr($png, $offset + 4, 4);
                if ($type === 'IHDR') {
                    self::assertSame($case['bits'], ord($png[$offset + 16]));
                    self::assertSame($case['components'] === 1 ? 0 : 2, ord($png[$offset + 17]));
                } elseif ($type === 'IDAT') {
                    $compressed .= substr($png, $offset + 8, $length);
                }
            }
            $filtered = gzuncompress($compressed);
            self::assertIsString($filtered);
            $stride = intdiv($case['width'] * $case['components'] * $case['bits'] + 7, 8);
            $samples = '';
            for ($row = 0; $row < $case['height']; ++$row) {
                self::assertSame(0, ord($filtered[$row * ($stride + 1)]));
                $samples .= substr($filtered, $row * ($stride + 1) + 1, $stride);
            }
            self::assertSame($case['samples'], base64_encode($samples), $case['name']);
        }
    }

    public function testPngEncodingReservesItsTemporaryAndFinalBuffers(): void
    {
        foreach ([new ReadingLimits(maximumContentBytes: 20), new ReadingLimits(maximumAssetsBytes: 20)] as $limits) {
            try {
                (new PngSampleEncoder(new ReadingBudget($limits)))->encode("\x12\x34", 1, 1, 1, 16);
                self::fail('The PNG buffer limit was bypassed.');
            } catch (ReadingException $error) {
                self::assertStringContainsString('budget', $error->getMessage());
            }
        }
    }

    public function testPngEncoderRejectsMismatchedSampleRows(): void
    {
        $this->expectExceptionMessage('do not match');
        (new PngSampleEncoder(new ReadingBudget(new ReadingLimits())))->encode("\x12", 1, 1, 1, 16);
    }

    public function testPngEncoderRejectsPackedRgbAndOverflowingDimensions(): void
    {
        foreach ([[1, 1, 3, 4], [PHP_INT_MAX, 2, 1, 16]] as $parameters) {
            try {
                (new PngSampleEncoder(new ReadingBudget(new ReadingLimits())))->encode('', ...$parameters);
                self::fail('Invalid PNG dimensions/depth were accepted.');
            } catch (ReadingException $error) {
                self::assertStringContainsString('dimensions', $error->getMessage());
            }
        }
    }
}
