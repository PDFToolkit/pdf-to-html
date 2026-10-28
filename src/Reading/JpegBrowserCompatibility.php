<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

/** Only pass JPEG through when browser colour/metadata conventions agree with the PDF. */
final readonly class JpegBrowserCompatibility
{
    public function __construct(private ReadingBudget $budget)
    {
    }

    public function matches(string $bytes, int $width, int $height, int $components, ?int $colorTransform): bool
    {
        if (!str_starts_with($bytes, "\xff\xd8")) {
            throw new ReadingException('A DCT image is missing its JPEG signature.');
        }
        $offset = 2;
        $length = strlen($bytes);
        $scanning = false;
        $frame = false;
        $adobe = null;
        $jfif = false;
        $rgbIdentifiers = false;
        $safe = true;
        while ($offset < $length) {
            if ($scanning) {
                $next = strpos($bytes, "\xff", $offset);
                if ($next === false) {
                    break;
                }
                $offset = $next;
            }
            if (ord($bytes[$offset++]) !== 255) {
                throw new ReadingException('A JPEG marker boundary is invalid.');
            }
            while ($offset < $length && ord($bytes[$offset]) === 255) {
                ++$offset;
            }
            if ($offset >= $length) {
                break;
            }
            $marker = ord($bytes[$offset++]);
            $this->budget->consume('instructions');
            if ($scanning && ($marker === 0 || ($marker >= 0xd0 && $marker <= 0xd7))) {
                continue;
            }
            $scanning = false;
            if ($marker === 0xd9) {
                $pdfTransform = $adobe ?? $colorTransform ?? ($components === 3 ? 1 : 0);
                $browserTransform = $jfif ? 1 : ($adobe ?? ($rgbIdentifiers ? 0 : 1));
                return $frame && $safe && ($components === 1 || $pdfTransform === $browserTransform);
            }
            if ($marker === 0 || $marker === 0xd8 || ($marker >= 0xd0 && $marker <= 0xd7) || $offset + 2 > $length) {
                throw new ReadingException('A JPEG marker is invalid or truncated.');
            }
            $size = (ord($bytes[$offset]) << 8) | ord($bytes[$offset + 1]);
            if ($size < 2 || $size > $length - $offset) {
                throw new ReadingException('A JPEG segment is truncated.');
            }
            $start = $offset + 2;
            $bodyLength = $size - 2;
            if (in_array($marker, [0xc0, 0xc1, 0xc2], true)) {
                if ($frame || $bodyLength < 6) {
                    throw new ReadingException('A JPEG frame is invalid or duplicated.');
                }
                $frame = true;
                $actualHeight = (ord($bytes[$start + 1]) << 8) | ord($bytes[$start + 2]);
                $actualWidth = (ord($bytes[$start + 3]) << 8) | ord($bytes[$start + 4]);
                $count = ord($bytes[$start + 5]);
                if ($actualWidth !== $width || $actualHeight !== $height || $count !== $components || $bodyLength !== 6 + 3 * $count) {
                    throw new ReadingException('JPEG frame dimensions or components disagree with the PDF image.');
                }
                $safe = $safe && ord($bytes[$start]) === 8;
                $rgbIdentifiers = $count === 3 && $bytes[$start + 6] === 'R' && $bytes[$start + 9] === 'G' && $bytes[$start + 12] === 'B';
            } elseif ($marker >= 0xc0 && $marker <= 0xcf && $marker !== 0xc4) {
                return false;
            } elseif ($marker === 0xee && $bodyLength >= 5 && substr($bytes, $start, 5) === 'Adobe') {
                if ($bodyLength !== 12 || ord($bytes[$start + 11]) > 1 || ($adobe !== null && $adobe !== ord($bytes[$start + 11]))) {
                    return false;
                }
                $adobe = ord($bytes[$start + 11]);
            } elseif ($marker === 0xe0 && $bodyLength >= 5 && substr($bytes, $start, 5) === "JFIF\0") {
                $jfif = true;
            } elseif (($marker === 0xe2 && $bodyLength >= 12 && substr($bytes, $start, 12) === "ICC_PROFILE\0")
                || ($marker === 0xe1 && $bodyLength >= 6 && substr($bytes, $start, 6) === "Exif\0\0")) {
                // Browsers apply these; the PDF image's colour space and matrix are authoritative.
                $safe = false;
            }
            $offset += $size;
            $scanning = $marker === 0xda;
        }
        throw new ReadingException('A JPEG image is missing its end marker.');
    }
}
