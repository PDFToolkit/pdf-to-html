<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Reading\ByteCursor;

/** Document-scoped MSB-first codec view; lookahead never searches for PDF operators. */
final class InlineFaxBits
{
    private int $buffer = 0;
    private int $remaining = 0;

    public function __construct(private readonly ByteCursor $cursor, private readonly ReadingBudget $budget)
    {
    }

    public function read(int $count = 1): int
    {
        $value = 0;
        for ($index = 0; $index < $count; ++$index) {
            if ($this->remaining === 0) {
                $this->budget->consume('instructions');
                $this->buffer = ord($this->cursor->read(1));
                $this->remaining = 8;
            }
            --$this->remaining;
            $value = ($value << 1) | (($this->buffer >> $this->remaining) & 1);
        }
        return $value;
    }

    public function peek(int $count): ?int
    {
        if ($count > $this->remaining + $this->cursor->remaining() * 8) {
            return null;
        }
        $position = $this->cursor->position();
        $buffer = $this->buffer;
        $remaining = $this->remaining;
        try {
            return $this->read($count);
        } finally {
            $this->cursor->seek($position);
            $this->buffer = $buffer;
            $this->remaining = $remaining;
        }
    }

    public function align(): void
    {
        if ($this->remaining > 0 && $this->read($this->remaining) !== 0) {
            throw new ReadingException('CCITT byte alignment requires zero padding bits.');
        }
    }

    public function hasEol(): bool
    {
        $next = $this->peek(12);
        return $next === 0 || $next === 1;
    }

    public function eol(): void
    {
        $zeros = 0;
        while ($this->read() === 0) {
            ++$zeros;
        }
        if ($zeros < 11) {
            throw new ReadingException('A CCITT line synchronization marker is invalid.');
        }
    }
}
