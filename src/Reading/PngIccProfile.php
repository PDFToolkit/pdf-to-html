<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Reading\UnsupportedPdfFeature;

/** Container validation only: the original ICC bytes remain authoritative. */
final readonly class PngIccProfile
{
    public function __construct(private ReadingBudget $budget)
    {
    }

    public function validate(string $bytes, int $components): void
    {
        if (strlen($bytes) < 132 || $this->word($bytes, 0) !== strlen($bytes) || substr($bytes, 36, 4) !== 'acsp') {
            throw new ReadingException('The image ICC profile has an invalid header or declared length.');
        }
        if (substr($bytes, 16, 4) !== ($components === 1 ? 'GRAY' : 'RGB ')
            || !in_array(substr($bytes, 20, 4), ['XYZ ', 'Lab '], true)
            || !in_array(substr($bytes, 12, 4), ['scnr', 'mntr', 'prtr', 'spac'], true)) {
            throw new UnsupportedPdfFeature('This ICC profile cannot be preserved in a gray/RGB PNG.');
        }
        $count = $this->word($bytes, 128);
        if ($count > intdiv(strlen($bytes) - 132, 12)) {
            throw new ReadingException('The image ICC tag table is truncated.');
        }
        $this->budget->consume('instructions', $count);
        for ($index = 0; $index < $count; ++$index) {
            $offset = $this->word($bytes, 136 + 12 * $index);
            $length = $this->word($bytes, 140 + 12 * $index);
            if ($offset < 132 + 12 * $count || $offset > strlen($bytes) || $length > strlen($bytes) - $offset) {
                throw new ReadingException('The image ICC tag lies outside its profile.');
            }
        }
    }
    private function word(string $bytes, int $offset): int
    {
        return (ord($bytes[$offset]) << 24) | (ord($bytes[$offset + 1]) << 16) | (ord($bytes[$offset + 2]) << 8) | ord($bytes[$offset + 3]);
    }
}
