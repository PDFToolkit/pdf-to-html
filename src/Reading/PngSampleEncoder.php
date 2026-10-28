<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

/** Exact PDF gray/RGB samples in legal PNG depths, with no colour transform or alpha approximation. */
final readonly class PngSampleEncoder
{
    public function __construct(private ReadingBudget $budget)
    {
    }

    public function encode(string $pixels, int $width, int $height, int $components, int $bits, bool $alpha = false, ?string $iccProfile = null): string
    {
        if ($width < 1 || $height < 1 || $width > 0x7fffffff || $height > 0x7fffffff
            || !in_array($components, [1, 3], true)
            || !in_array($bits, $components === 1 && !$alpha ? [1, 2, 4, 8, 16] : [8, 16], true)
            || $width > intdiv(PHP_INT_MAX - 7, ($components + (int) $alpha) * $bits)) {
            throw new ReadingException('PNG requires valid dimensions and a supported gray/RGB sample depth.');
        }
        $row = intdiv($width * ($components + (int) $alpha) * $bits + 7, 8);
        if ($row >= intdiv($this->budget->limits->maximumContentBytes, $height)) {
            throw new ReadingException('PNG scanlines exceed the source content budget.');
        }
        if (strlen($pixels) !== $row * $height) {
            throw new ReadingException('Image samples do not match their declared dimensions.');
        }
        $scanlineBytes = ($row + 1) * $height;
        // zlib's compressBound plus the encoded chunk/CRC and final PNG assembly buffers.
        $compressedBound = $scanlineBytes + intdiv($scanlineBytes, 4096) + intdiv($scanlineBytes, 16384) + intdiv($scanlineBytes, 33554432) + 13;
        $this->budget->consume('content', $scanlineBytes);
        $this->budget->consume('content', $compressedBound);
        $this->budget->consume('content', $compressedBound);
        $this->budget->consume('content', $compressedBound);
        $scanlines = '';
        for ($index = 0; $index < $height; ++$index) {
            $scanlines .= "\0" . substr($pixels, $index * $row, $row);
        }
        $compressed = gzcompress($scanlines);
        if ($compressed === false || strlen($compressed) > $compressedBound) {
            throw new ReadingException('PNG sample compression failed or exceeded its bound.');
        }
        $profile = '';
        if ($iccProfile !== null) {
            (new PngIccProfile($this->budget))->validate($iccProfile, $components);
            $bound = strlen($iccProfile) + intdiv(strlen($iccProfile), 4096) + intdiv(strlen($iccProfile), 16384) + 32;
            $this->budget->consume('content', $bound * 4);
            $encoded = gzcompress($iccProfile);
            if ($encoded === false || strlen($encoded) > $bound) {
                throw new ReadingException('PNG ICC compression failed or exceeded its bound.');
            }
            $profile = $this->chunk('iCCP', "PDF ICC\0\0" . $encoded);
        }
        $this->budget->consume('assets', strlen($compressed) + strlen($profile) + 57);
        return "\x89PNG\r\n\x1a\n"
            . $this->chunk('IHDR', pack('NNCCCCC', $width, $height, $bits, ($components === 3 ? 2 : 0) + ($alpha ? 4 : 0), 0, 0, 0))
            . $profile
            . $this->chunk('IDAT', $compressed) . $this->chunk('IEND', '');
    }

    private function chunk(string $type, string $bytes): string
    {
        return pack('N', strlen($bytes)) . $type . $bytes . pack('N', crc32($type . $bytes));
    }
}
