<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Document\Fonts\CMaps\UnicodeSequence;
use PDF\Reading\EncodedStream;
use PDF\Reading\ReadDocument;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringType;

/** Declarative ToUnicode parsing with bounded expansion; never executes PostScript. */
final class ToUnicodeReader
{
    /** @var array<int, true> */
    private array $loading = [];
    /** @var array<int, ReadUnicodeMap> */
    private array $cache = [];
    public function __construct(private readonly ReadDocument $document, private readonly ReadingBudget $budget)
    {
    }
    public function read(EncodedStream $stream, int $depth = 1): ReadUnicodeMap
    {
        $this->budget->depth($depth);
        $identity = spl_object_id($stream);
        if (isset($this->loading[$identity])) {
            throw new ReadingException('ToUnicode inheritance contains a cycle.');
        }
        if (isset($this->cache[$identity])) {
            return $this->cache[$identity];
        }
        $this->loading[$identity] = true;
        try {
            $mappings = [];
            $spaces = [];
            $base = $stream->dictionary()->get('UseCMap');
            if ($base !== null) {
                $base = $this->document->resolve($base);
                if (!$base instanceof EncodedStream) {
                    throw new ReadingException('A named ToUnicode UseCMap requires an explicitly supplied resource.');
                }
                $inherited = $this->read($base, $depth + 1);
                $mappings = $inherited->mappings;
                $spaces = $inherited->spaces;
            }
            // PHP arrays copy on the first local write. Charge inherited snapshots before that allocation.
            $inheritedEntries = count($mappings) + count($spaces);
            $mode = null;
            $expected = 0;
            foreach ((new ContentInstructionReader($this->budget))->read($this->document->streamData($stream)) as $instruction) {
                $operator = $instruction->operator;
                if (in_array($operator, ['begincodespacerange', 'beginbfchar', 'beginbfrange'], true)) {
                    $count = $instruction->operands[0] ?? null;
                    if ($mode !== null || !$count instanceof IntegerType || $count->integer() < 0) {
                        throw new ReadingException('A ToUnicode mapping block has an invalid count or nesting.');
                    }
                    $expected = $count->integer();
                    $mode = $operator;
                    continue;
                }
                if (in_array($operator, ['endcodespacerange', 'endbfchar', 'endbfrange'], true)) {
                    $required = match ($operator) {
                        'endcodespacerange' => 'begincodespacerange', 'endbfchar' => 'beginbfchar', default => 'beginbfrange'
                    };
                    if ($mode !== $required) {
                        throw new ReadingException('ToUnicode mapping delimiters do not match.');
                    }
                    $pending = $instruction->operands;
                    $width = $mode === 'beginbfrange' ? 3 : 2;
                    if (count($pending) !== $expected * $width) {
                        throw new ReadingException('A ToUnicode mapping block count does not match its entries.');
                    }
                    if ($expected > 0 && $inheritedEntries > 0) {
                        $this->budget->consume('mappings', $inheritedEntries);
                        $inheritedEntries = 0;
                    }
                    for ($index = 0; $index < count($pending); $index += $width) {
                        $low = $this->bytes($pending[$index]);
                        if ($mode === 'begincodespacerange') {
                            $this->budget->consume('mappings');
                            $high = $this->bytes($pending[$index + 1]);
                            $this->range($low, $high);
                            $spaces[] = [$low, $high];
                        } elseif ($mode === 'beginbfchar') {
                            $this->budget->consume('mappings');
                            $unicode = $this->unicode($pending[$index + 1]);
                            $this->budget->consume('retainedText', strlen($unicode));
                            $mappings[bin2hex($low)] = $unicode;
                        } else {
                            $high = $this->bytes($pending[$index + 1]);
                            $this->range($low, $high);
                            $start = $this->number($low);
                            $end = $this->number($high);
                            $this->budget->consume('mappings', $end - $start + 1);
                            $destination = $pending[$index + 2];
                            if ($destination instanceof ArrayType && count($destination) !== $end - $start + 1) {
                                throw new ReadingException('A ToUnicode bfrange array must match its source range.');
                            }
                            $sequence = $destination instanceof StringType ? new UnicodeSequence(new HexadecimalString($destination->value)) : null;
                            if ($sequence === null && !$destination instanceof ArrayType) {
                                throw new ReadingException('A ToUnicode bfrange requires a string or array destination.');
                            }
                            for ($code = $start; $code <= $end; ++$code) {
                                $key = substr(pack('N', $code), -strlen($low));
                                $unicode = $sequence !== null ? $sequence->increment($code - $start)->utf8() : $this->unicode($destination->items[$code - $start]);
                                $this->budget->consume('retainedText', strlen($unicode));
                                $mappings[bin2hex($key)] = $unicode;
                            }
                        }
                    }
                    $mode = null;
                } elseif ($operator === 'usecmap') {
                    throw new ReadingException('Program-level named usecmap requires an explicitly supplied resource.');
                }
            }
            if ($mode !== null || $spaces === []) {
                throw new ReadingException('ToUnicode requires closed mapping blocks and a codespace.');
            }
            return $this->cache[$identity] = new ReadUnicodeMap($mappings, $spaces, $this->budget);
        } catch (\InvalidArgumentException|\OverflowException $exception) {
            throw new ReadingException('A ToUnicode mapping is invalid: ' . $exception->getMessage(), 0, $exception);
        } finally {
            unset($this->loading[$identity]);
        }
    }
    private function bytes(BasicType $value): string
    {
        if (!$value instanceof StringType || strlen($value->value) < 1 || strlen($value->value) > 4) {
            throw new ReadingException('A ToUnicode source code must contain one to four bytes.');
        }
        return $value->value;
    }
    private function unicode(BasicType $value): string
    {
        if (!$value instanceof StringType) {
            throw new ReadingException('A ToUnicode destination must be a string.');
        }
        return (new UnicodeSequence(new HexadecimalString($value->value)))->utf8();
    }
    private function range(string $low, string $high): void
    {
        if (strlen($low) !== strlen($high) || strcmp($low, $high) > 0) {
            throw new ReadingException('A ToUnicode source range is malformed.');
        }
    }
    private function number(string $bytes): int
    {
        $number = 0;
        foreach (str_split($bytes) as $byte) {
            $number = $number * 256 + ord($byte);
        }
        return $number;
    }
}
