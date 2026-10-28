<?php

declare(strict_types=1);

namespace PDF\Html\Tests;

use PDF\Html\Reading\DecodedImagePng;
use PDF\Html\Reading\JpegBrowserCompatibility;
use PDF\Html\Reading\ReadingBudget;
use PDF\Html\Reading\ReadingException;
use PDF\Html\Reading\ReadingLimits;
use PDF\Html\Reading\SourceValues;
use PDF\Reading\ByteCursor;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\ImageCodecs\ChannelDefinition;
use PDF\Reading\ImageCodecs\ColorInformation;
use PDF\Reading\ImageCodecs\ComponentPlane;
use PDF\Reading\ImageCodecs\DecodedImage;
use PDF\Reading\SyntaxParser;
use PDF\Reading\UnsupportedPdfFeature;
use PDF\Syntax\Object\DictionaryType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecodedImagePngTest extends TestCase
{
    public function testJpxIgnoresDictionaryBitDepthAndDecodeWithoutPdfColourSpace(): void
    {
        $image = new DecodedImage(3, 1, [new ComponentPlane(3, 1, 4, false, "\x1a\xf0")], new ColorInformation('Gray', 17));
        $png = $this->encode($image, '/BitsPerComponent 8 /Decode [1 0]');
        self::assertSame(4, ord($png[24]));
        self::assertSame("\0\x1a\xf0", gzuncompress($this->chunk($png, 'IDAT')));
        $inverted = $this->encode($image, '/ColorSpace /DeviceGray /Decode [1 0]');
        self::assertSame("\0\xe5\0", gzuncompress($this->chunk($inverted, 'IDAT')));
    }

    public function testMixedLegalDepthsExpandExactlyWithoutEightBitTruncation(): void
    {
        $image = new DecodedImage(1, 1, [new ComponentPlane(1, 1, 4, false, "\xa0"), new ComponentPlane(1, 1, 8, false, "\x12"), new ComponentPlane(1, 1, 16, false, "\x34\x56")]);
        $png = $this->encode($image, '/ColorSpace /DeviceRGB');
        self::assertSame(16, ord($png[24]));
        self::assertSame("\0\xaa\xaa\x12\x12\x34\x56", gzuncompress($this->chunk($png, 'IDAT')));
    }

    public function testCommonNonzeroOriginsDoNotMoveSamples(): void
    {
        $image = new DecodedImage(1, 1, [new ComponentPlane(1, 1, 8, false, 'a', originX: 11, originY: 7)], originX: 11, originY: 7);
        self::assertSame("\0a", gzuncompress($this->chunk($this->encode($image, '/ColorSpace /DeviceGray'), 'IDAT')));
    }

    public function testOpacityIsIgnoredByDefaultAndChromaKeyIsNeverApplied(): void
    {
        $image = $this->opacity('unassociated', 51, 85);
        $image = new DecodedImage(1, 1, $image->components, $image->color, channelDefinitions: $image->channelDefinitions, chromaKey: [51]);
        $png = $this->encode($image);
        self::assertSame(0, ord($png[25]));
        self::assertSame("\0\x33", gzuncompress($this->chunk($png, 'IDAT')));
        $png = $this->encode($image, '/SMaskInData 1');
        self::assertSame(4, ord($png[25]));
        self::assertSame("\0\x33\x55", gzuncompress($this->chunk($png, 'IDAT')));
    }

    public function testExactlyRepresentablePremultipliedOpacityIsRecovered(): void
    {
        $png = $this->encode($this->opacity('associated', 51, 85), '/SMaskInData 2');
        self::assertSame("\0\x99\x55", gzuncompress($this->chunk($png, 'IDAT')));
        $png = $this->encode($this->opacity('associated', 0, 0), '/SMaskInData 2');
        self::assertSame("\0\0\0", gzuncompress($this->chunk($png, 'IDAT')));
    }

    public function testPremultipliedOpacityNeverRoundsAwaySamplePrecision(): void
    {
        $this->expectException(UnsupportedPdfFeature::class);
        $this->expectExceptionMessage('rounding');
        $this->encode($this->opacity('associated', 51, 86), '/SMaskInData 2');
    }

    public function testOpacityMustApplyToAllColourChannels(): void
    {
        $image = $this->opacity('unassociated', 51, 85, 65535);
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('every colour');
        $this->encode($image, '/SMaskInData 1');
    }

    /** @return iterable<string, array{ComponentPlane}> */
    public static function unrepresentablePlanes(): iterable
    {
        yield 'twelve bits cannot fit PNG without losing normalised precision' => [new ComponentPlane(1, 1, 12, false, "\xab\xc0")];
        yield 'signed samples are not silently reinterpreted' => [new ComponentPlane(1, 1, 8, true, "\xff")];
        yield 'subsampled grid is not silently repeated' => [new ComponentPlane(1, 1, 8, false, 'a', separationX: 2)];
    }

    #[DataProvider('unrepresentablePlanes')]
    public function testUnrepresentablePlanesRequireExplicitRendering(ComponentPlane $plane): void
    {
        $this->expectException(UnsupportedPdfFeature::class);
        $this->encode(new DecodedImage(1, 1, [$plane]), '/ColorSpace /DeviceGray');
    }

    public function testJpxEmbeddedProfileIsRetainedExactlyButPdfDeviceSpaceOverridesIt(): void
    {
        $profile = (string) file_get_contents(__DIR__ . '/Fixtures/codec-images/gray.icc');
        $image = new DecodedImage(1, 1, [new ComponentPlane(1, 1, 8, false, 'a')], new ColorInformation('ICC', iccProfile: $profile));
        $png = $this->encode($image);
        self::assertSame($profile, gzuncompress(substr($this->chunk($png, 'iCCP'), 9)));
        self::assertSame('', $this->chunk($this->encode($image, '/ColorSpace /DeviceGray'), 'iCCP'));
    }

    public function testImageDimensionsMustMatchAndNoBufferIsAllocatedOverBudget(): void
    {
        $image = new DecodedImage(1, 1, [new ComponentPlane(1, 1, 8, false, 'a')]);
        try {
            $this->encoder()->encode($image, $this->dictionary('/ColorSpace /DeviceGray'), true, 2, 1);
            self::fail('Mismatched dimensions were accepted.');
        } catch (ReadingException $error) {
            self::assertStringContainsString('dimensions', $error->getMessage());
        }
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('budget');
        $this->encoder(new ReadingLimits(maximumContentBytes: 1))->encode($image, $this->dictionary('/ColorSpace /DeviceGray'), true, 1, 1);
    }

    public function testJpegTransformAndMetadataControlBrowserPassthrough(): void
    {
        $jpeg = (string) file_get_contents(__DIR__ . '/Fixtures/codec-images/rgb.jpg');
        $reader = new JpegBrowserCompatibility(new ReadingBudget(new ReadingLimits()));
        self::assertTrue($reader->matches($jpeg, 13, 7, 3, null));
        self::assertTrue($reader->matches($jpeg, 13, 7, 3, 1));
        self::assertFalse($reader->matches($jpeg, 13, 7, 3, 0));
        $adobe = 'Adobe' . pack('nnnC', 100, 0, 0, 0);
        $rgb = $this->segment($jpeg, 0xee, $adobe);
        self::assertFalse($reader->matches($rgb, 13, 7, 3, 1), 'PDF APP14 and browser JFIF priority disagree.');
        $withoutJfif = substr($jpeg, 0, 2) . substr($jpeg, 20);
        self::assertTrue($reader->matches($this->segment($withoutJfif, 0xee, $adobe), 13, 7, 3, 1), 'Without JFIF, APP14 is authoritative for both.');
        self::assertFalse($reader->matches($this->segment($jpeg, 0xe2, "ICC_PROFILE\0\1\1profile"), 13, 7, 3, 1));
        self::assertFalse($reader->matches($this->segment($jpeg, 0xe1, "Exif\0\0metadata"), 13, 7, 3, 1));
    }

    private function segment(string $jpeg, int $marker, string $body): string
    {
        return substr($jpeg, 0, 2) . "\xff" . chr($marker) . pack('n', strlen($body) + 2) . $body . substr($jpeg, 2);
    }

    private function opacity(string $type, int $value, int $alpha, int $association = 0): DecodedImage
    {
        return new DecodedImage(1, 1, [new ComponentPlane(1, 1, 8, false, chr($value)), new ComponentPlane(1, 1, 8, false, chr($alpha), alpha: $type)], new ColorInformation('Gray', 17), channelDefinitions: [new ChannelDefinition(0, 0, 1, 0), new ChannelDefinition(1, $type === 'associated' ? 2 : 1, $association, 1)]);
    }

    private function encoder(?ReadingLimits $limits = null): DecodedImagePng
    {
        $document = (new DocumentReader())->read(new FileBytes((string) file_get_contents(__DIR__ . '/Fixtures/tagged.pdf')));
        return new DecodedImagePng(new SourceValues($document, new ReadingBudget($limits ?? new ReadingLimits())));
    }

    private function encode(DecodedImage $image, string $dictionary = ''): string
    {
        return $this->encoder()->encode($image, $this->dictionary($dictionary), true, $image->width, $image->height);
    }

    private function dictionary(string $body): DictionaryType
    {
        $value = (new SyntaxParser(new ByteCursor('<<' . $body . '>>')))->read();
        self::assertInstanceOf(DictionaryType::class, $value);
        return $value;
    }

    private function chunk(string $png, string $name): string
    {
        for ($offset = 8; $offset < strlen($png); $offset += 12 + $length) {
            $length = unpack('N', $png, $offset)[1];
            if (substr($png, $offset + 4, 4) === $name) {
                return substr($png, $offset + 8, $length);
            }
        }
        return '';
    }
}
