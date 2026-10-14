<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Reading\ByteCursor;

/** Codec framing establishes the endpoint; pixel bytes never act as PDF EI delimiters. */
final readonly class InlineImageBoundaryReader
{
    public function __construct(private ReadingBudget $budget)
    {
    }

    public function read(ByteCursor $cursor, ?string $filter, ?int $rawLength, int $earlyChange = 1): string
    {
        $start = $cursor->position();
        match ($filter) {
            null => $this->raw($cursor, $rawLength),
            'ASCIIHexDecode' => $this->asciiHex($cursor),
            'ASCII85Decode' => $this->ascii85($cursor),
            'RunLengthDecode' => $this->runLength($cursor),
            'FlateDecode' => $this->flate($cursor),
            'DCTDecode' => $this->jpeg($cursor),
            'LZWDecode' => $this->lzw($cursor, $earlyChange),
            default => throw new ReadingException('The inline image filter has no supported verifiable endpoint: ' . $filter . '.'),
        };
        $length = $cursor->position() - $start;
        $cursor->seek($start);
        return $cursor->read($length);
    }

    private function raw(ByteCursor $cursor, ?int $length): void
    {
        if ($length === null) {
            throw new ReadingException('The raw inline image colour space does not establish a sample length.');
        }
        $cursor->read($length);
    }

    private function asciiHex(ByteCursor $cursor): void
    {
        while (!$cursor->eof()) {
            $this->budget->consume('instructions');
            $byte = $cursor->read(1);
            if ($byte === '>') {
                return;
            }
            if (!ctype_xdigit($byte) && !str_contains("\0\t\n\f\r ", $byte)) {
                throw new ReadingException('An ASCIIHex inline image contains a non-hexadecimal byte.');
            }
        }
        throw new ReadingException('An ASCIIHex inline image is missing its end marker.');
    }

    private function ascii85(ByteCursor $cursor): void
    {
        $group = 0;
        while (!$cursor->eof()) {
            $this->budget->consume('instructions');
            $byte = $cursor->read(1);
            if (str_contains("\0\t\n\f\r ", $byte)) {
                continue;
            }
            if ($byte === '~' && $cursor->read(1) === '>') {
                if ($group === 1) {
                    throw new ReadingException('An ASCII85 inline image has a one-character final group.');
                }
                return;
            }
            if ($byte === 'z' && $group === 0) {
                continue;
            }
            if (ord($byte) < 33 || ord($byte) > 117) {
                throw new ReadingException('An ASCII85 inline image contains an invalid encoded byte.');
            }
            $group = ($group + 1) % 5;
        }
        throw new ReadingException('An ASCII85 inline image is missing its end marker.');
    }

    private function runLength(ByteCursor $cursor): void
    {
        while (!$cursor->eof()) {
            $this->budget->consume('instructions');
            $packet = ord($cursor->read(1));
            if ($packet === 128) {
                return;
            }
            $cursor->read($packet < 128 ? $packet + 1 : 1);
        }
        throw new ReadingException('A RunLength inline image is missing its end marker.');
    }

    private function flate(ByteCursor $cursor): void
    {
        $context = @inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($context === false) {
            throw new ReadingException('Could not initialize the inline image Flate boundary decoder.');
        }
        $start = $cursor->position();
        while (!$cursor->eof()) {
            $this->budget->consume('instructions');
            // Small encoded slices bound each temporary output, even for high expansion.
            $decoded = @inflate_add($context, $cursor->read(min(64, $cursor->remaining())), ZLIB_SYNC_FLUSH);
            if ($decoded === false) {
                throw new ReadingException('The inline image contains invalid Flate data.');
            }
            $this->budget->consume('content', strlen($decoded));
            if (inflate_get_status($context) === ZLIB_STREAM_END) {
                $cursor->seek($start + inflate_get_read_len($context));
                return;
            }
        }
        throw new ReadingException('A Flate inline image is truncated.');
    }

    private function jpeg(ByteCursor $cursor): void
    {
        if ($cursor->read(2) !== "\xff\xd8") {
            throw new ReadingException('An inline DCT image is missing its JPEG SOI marker.');
        }
        $entropy = false;
        $scan = false;
        while (!$cursor->eof()) {
            $this->budget->consume('instructions');
            if ($entropy) {
                $part = $cursor->peek(min(4096, $cursor->remaining()));
                $markerOffset = strpos($part, "\xff");
                $cursor->read($markerOffset === false ? strlen($part) : $markerOffset);
                if ($markerOffset === false) {
                    continue;
                }
            }
            if ($cursor->read(1) !== "\xff") {
                throw new ReadingException('An inline JPEG segment is missing its marker prefix.');
            }
            do {
                $marker = ord($cursor->read(1));
            } while ($marker === 255);
            if ($entropy && ($marker === 0 || $marker >= 208 && $marker <= 215)) {
                continue;
            }
            if ($marker === 217) {
                if (!$scan) {
                    throw new ReadingException('An inline JPEG has no image scan before EOI.');
                }
                return;
            }
            if ($marker === 0 || $marker === 216 || $marker >= 208 && $marker <= 215) {
                throw new ReadingException('An inline JPEG contains an unexpected marker.');
            }
            if ($marker === 1) {
                continue;
            }
            $length = ord($cursor->read(1)) * 256 + ord($cursor->read(1));
            if ($length < 2) {
                throw new ReadingException('An inline JPEG segment length is invalid.');
            }
            $cursor->read($length - 2);
            $entropy = $marker === 218 || $entropy && $marker === 220;
            $scan = $scan || $entropy;
        }
        throw new ReadingException('An inline JPEG is missing its EOI marker.');
    }

    private function lzw(ByteCursor $cursor, int $earlyChange): void
    {
        if ($earlyChange !== 0 && $earlyChange !== 1) {
            throw new ReadingException('Inline LZW EarlyChange must be zero or one.');
        }
        $bits = 0;
        $buffer = 0;
        $width = 9;
        $next = 258;
        $previous = false;
        while (true) {
            $this->budget->consume('instructions');
            while ($bits < $width) {
                $buffer = ($buffer << 8) | ord($cursor->read(1));
                $bits += 8;
            }
            $bits -= $width;
            $code = ($buffer >> $bits) & ((1 << $width) - 1);
            $buffer &= (1 << $bits) - 1;
            if ($code === 257) {
                return;
            }
            if ($code === 256) {
                $width = 9;
                $next = 258;
                $previous = false;
                continue;
            }
            if ($code > $next || $code === $next && !$previous) {
                throw new ReadingException('An inline LZW stream refers to an undefined code.');
            }
            if ($previous && $next < 4096) {
                ++$next;
                $width = $this->lzwWidth($next, $earlyChange);
            }
            $previous = true;
        }
    }

    private function lzwWidth(int $next, int $earlyChange): int
    {
        return match (true) {
            $next + $earlyChange < 512 => 9,
            $next + $earlyChange < 1024 => 10,
            $next + $earlyChange < 2048 => 11,
            default => 12,
        };
    }
}
