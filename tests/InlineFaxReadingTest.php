<?php

declare(strict_types=1);

namespace PDF\Html\Tests;

use PDF\Html\PdfToHtml;
use PDF\Html\Reading\ContentInstructionReader;
use PDF\Html\Reading\InlineImageBoundaryProvider;
use PDF\Html\Reading\InlineImageData;
use PDF\Html\Reading\ReadingBudget;
use PDF\Html\Reading\ReadingException;
use PDF\Html\Reading\ReadingLimits;
use PDF\Html\Reading\UnsupportedInlineImageFeature;
use PDF\Reading\ByteCursor;
use PDF\Reading\FileBytes;
use PDF\Syntax\Object\DictionaryType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InlineFaxReadingTest extends TestCase
{
    public function testIndependentLibtiffOneAndTwoDimensionalSamplesReachExactEndpoints(): void
    {
        $json = file_get_contents(__DIR__ . '/Fixtures/inline-fax-cases.json');
        self::assertIsString($json);
        $manifest = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['cases'] as $case) {
            $parameters = '';
            foreach ($case['params'] as $key => $value) {
                $parameters .= '/' . $key . ' ' . (is_bool($value) ? ($value ? 'true' : 'false') : $value) . ' ';
            }
            $data = base64_decode($case['encoded'], true);
            self::assertIsString($data);
            $instructions = iterator_to_array($this->reader()->read('BI /W ' . $case['width'] . ' /H ' . $case['height'] . ' /BPC 1 /CS /G /F /CCF /DP <<' . $parameters . '>> ID ' . $data . ' EI q Q'), false);
            self::assertSame(['BI', 'q', 'Q'], array_column($instructions, 'operator'), $case['name']);
            self::assertInstanceOf(InlineImageData::class, $instructions[0]->inlineImage);
            self::assertSame($case['encodedSha256'], hash('sha256', $instructions[0]->inlineImage->bytes), $case['name']);
        }
    }

    public function testFaxPdfDerivesDimensionedPlaceholdersAndFollowingText(): void
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/inline-fax.pdf');
        self::assertIsString($bytes);
        $result = (new PdfToHtml())->convert(new FileBytes($bytes), 'fax.pdf');
        self::assertSame(7, substr_count($result->html, '<img '));
        self::assertStringContainsString('Following the fax images', $result->html);
        self::assertCount(7, array_filter($result->diagnostics, static fn ($item): bool => $item->code === 'image-placeholder'));
    }

    public function testRowsWithoutEndOfBlockStopAtTheExactByteBoundary(): void
    {
        // Two Group 4 all-white rows, code V(0) = 1 each, then octet padding.
        $instructions = iterator_to_array($this->reader()->read("BI /W 8 /H 2 /BPC 1 /CS /G /F /CCF /DP <</K -1 /Columns 8 /Rows 2 /EndOfBlock false>> ID \xc0 EI q Q"), false);
        self::assertSame("\xc0", $instructions[0]->inlineImage?->bytes);
        self::assertSame('q', $instructions[1]->operator);
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedFax(): iterable
    {
        yield 'EOFB inside an incomplete row' => ['/K -1 /Columns 8', self::bits('010' . '000000000001000000000001')];
        yield 'no terminal RTC' => ['/K 0 /Columns 8 /EndOfLine true', self::bits('00000000000110011')];
        yield 'required EOL missing' => ['/K 0 /Columns 8 /EndOfLine true', self::bits('10011')];
        yield 'run exceeds columns' => ['/K 0 /Columns 4 /Rows 1 /EndOfBlock false', self::bits('10011')];
        yield 'nonzero padding' => ['/K -1 /Columns 8 /Rows 1 /EndOfBlock false', "\xff"];
        yield 'invalid parameter type' => ['/K (fax)', "\x00"];
        yield 'missing bit endpoint' => ['/K -1 /Columns 8', "\xff"];
    }

    #[DataProvider('malformedFax')]
    public function testMalformedCodewordsCannotUseEiAsFallback(string $parameters, string $data): void
    {
        $this->expectException(ReadingException::class);
        iterator_to_array($this->reader()->read('BI /W 8 /H 1 /BPC 1 /CS /G /F /CCF /DP <<' . $parameters . '>> ID ' . $data . ' EI q Q'));
    }

    public function testOptionalExtensionsRequireAnExplicitCodecProvider(): void
    {
        $this->expectException(UnsupportedInlineImageFeature::class);
        $this->expectExceptionMessage('InlineImageBoundaryProvider');
        iterator_to_array($this->reader()->read('BI /W 8 /H 1 /BPC 1 /CS /G /F /CCF /DP <</K -1 /Columns 8>> ID ' . self::bits('0000001111') . ' EI'));
    }

    public function testExplicitProviderIsValidatedAndDoesNotRescueMalformedKnownCodecs(): void
    {
        $provider = new class () implements InlineImageBoundaryProvider {
            public function locate(ByteCursor $cursor, DictionaryType $dictionary, ReadingBudget $budget): void
            {
                $budget->consume('instructions');
                $cursor->read(3);
            }
        };
        $reader = new ContentInstructionReader(new ReadingBudget(new ReadingLimits()), inlineImages: $provider);
        $instructions = iterator_to_array($reader->read('BI /W 1 /H 1 /BPC 8 /CS /G /F /ApplicationCodec ID abc EI q Q'), false);
        self::assertSame('abc', $instructions[0]->inlineImage?->bytes);
        self::assertSame('q', $instructions[1]->operator);
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('ASCIIHex');
        iterator_to_array($reader->read('BI /W 1 /H 1 /BPC 8 /CS /G /F /AHx ID abc EI'));
    }

    public function testFaxCodewordLoopsRespectCumulativeBudgets(): void
    {
        $reader = new ContentInstructionReader(new ReadingBudget(new ReadingLimits(maximumNodes: 8)));
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('cumulative source nodes');
        iterator_to_array($reader->read('BI /W 8 /H 100 /BPC 1 /CS /G /F /CCF /DP <</K -1 /Columns 8 /Rows 100 /EndOfBlock false>> ID ' . self::bits(str_repeat('1', 100)) . ' EI'));
    }

    private function reader(): ContentInstructionReader
    {
        return new ContentInstructionReader(new ReadingBudget(new ReadingLimits()));
    }

    private static function bits(string $bits): string
    {
        $bits = str_pad($bits, (int) ceil(strlen($bits) / 8) * 8, '0');
        $bytes = '';
        for ($offset = 0; $offset < strlen($bits); $offset += 8) {
            $bytes .= chr(intval(substr($bits, $offset, 8), 2));
        }
        return $bytes;
    }
}
