<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Reading\ByteCursor;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NullType;

/** ITU-T T.4/T.6 codeword parsing: only completed rows can introduce RTC or EOFB. */
final readonly class InlineFaxBoundaryReader
{
    public function __construct(private ReadingBudget $budget, private InlineFaxCodes $codes = new InlineFaxCodes())
    {
    }

    public function read(ByteCursor $cursor, DictionaryType $image): void
    {
        $parameters = $image->get('DecodeParms');
        $parameters = $parameters instanceof ArrayType ? ($parameters->items[0] ?? null) : $parameters;
        $parameters = $parameters instanceof DictionaryType ? $parameters : new DictionaryType();
        $k = $this->integer($parameters, 'K', 0);
        $columns = $this->integer($parameters, 'Columns', 1728);
        $rows = $this->integer($parameters, 'Rows', 0);
        $endOfBlock = $this->boolean($parameters, 'EndOfBlock', true);
        $endOfLine = $this->boolean($parameters, 'EndOfLine', false);
        $align = $this->boolean($parameters, 'EncodedByteAlign', false);
        $this->boolean($parameters, 'BlackIs1', false);
        if ($columns < 1 || $rows < 0) {
            throw new ReadingException('CCITT Columns must be positive and Rows must be nonnegative.');
        }
        if ($columns > $this->budget->limits->maximumContentBytes * 8) {
            throw new ReadingException('CCITT Columns exceed the configured sample bit budget.');
        }
        $damaged = $this->integer($parameters, 'DamagedRowsBeforeError', 0);
        if ($damaged < 0) {
            throw new ReadingException('CCITT DamagedRowsBeforeError must be nonnegative.');
        }
        if ($damaged > 0 && $endOfLine && $k >= 0) {
            throw new UnsupportedInlineImageFeature('CCITT damaged-row recovery requires an explicit InlineImageBoundaryProvider.');
        }
        if (!$endOfBlock && $rows === 0) {
            throw new UnsupportedInlineImageFeature('CCITT without EndOfBlock requires a positive Rows count or an explicit InlineImageBoundaryProvider.');
        }
        $bits = new InlineFaxBits($cursor, $this->budget);
        $reference = [$columns];
        for ($row = 0; ; ++$row) {
            if (!$endOfBlock && $row === $rows) {
                $bits->align();
                return;
            }
            $this->budget->consume('nodes');
            if ($align) {
                $bits->align();
            }
            if ($k < 0 && $bits->peek(24) === 0x001001) {
                if (!$endOfBlock) {
                    throw new ReadingException('CCITT EOFB occurs before the declared Rows count.');
                }
                $bits->read(24);
                $bits->align();
                return;
            }
            $oneDimensional = $k === 0;
            if ($bits->hasEol()) {
                $bits->eol();
                $tag = $k > 0 ? $bits->read() : 1;
                if ($k >= 0 && $bits->hasEol()) {
                    for ($marker = 1; $marker < 6; ++$marker) {
                        $bits->eol();
                        if ($k > 0 && $bits->read() !== 1) {
                            throw new ReadingException('CCITT RTC requires one-dimensional tag bits.');
                        }
                    }
                    if (!$endOfBlock || $tag !== 1) {
                        throw new ReadingException('CCITT RTC occurs before its expected termination.');
                    }
                    $bits->align();
                    return;
                }
                $oneDimensional = $k === 0 || $k > 0 && $tag === 1;
            } elseif ($endOfLine) {
                throw new ReadingException('CCITT EndOfLine requires a synchronization marker before each row.');
            } elseif ($k > 0) {
                $oneDimensional = $bits->read() === 1;
            }
            $reference = $oneDimensional ? $this->oneDimensional($bits, $columns) : $this->twoDimensional($bits, $columns, $reference);
        }
    }

    /** @return list<int> */
    private function oneDimensional(InlineFaxBits $bits, int $columns): array
    {
        $position = 0;
        $white = true;
        $changes = [];
        while ($position < $columns) {
            $position += $this->run($bits, $white, $columns - $position);
            $this->change($changes, $position);
            $white = !$white;
        }
        return $changes;
    }

    /**
     * @param list<int> $reference
     * @return list<int>
     */
    private function twoDimensional(InlineFaxBits $bits, int $columns, array $reference): array
    {
        $position = 0;
        $white = true;
        $initial = true;
        $referenceIndex = 0;
        $changes = [];
        while ($position < $columns) {
            $this->budget->consume('instructions');
            while (isset($reference[$referenceIndex]) && ($reference[$referenceIndex] < $position || !$initial && $reference[$referenceIndex] === $position)) {
                ++$referenceIndex;
            }
            $bIndex = $referenceIndex + ($referenceIndex % 2 === ($white ? 0 : 1) ? 0 : 1);
            $b1 = $reference[$bIndex] ?? $columns;
            $b2 = $reference[$bIndex + 1] ?? $columns;
            $mode = $this->mode($bits);
            if ($mode === 'pass') {
                if ($b2 <= $position) {
                    throw new ReadingException('CCITT pass mode must advance its coding position.');
                }
                $position = $b2;
            } elseif ($mode === 'horizontal') {
                $position += $this->run($bits, $white, $columns - $position);
                $this->change($changes, $position);
                $position += $this->run($bits, !$white, $columns - $position);
                $this->change($changes, $position);
            } else {
                $next = $b1 + $mode;
                if ($next < $position || $next > $columns) {
                    throw new ReadingException('CCITT vertical mode lies outside its coding row.');
                }
                $position = $next;
                $this->change($changes, $position);
                $white = !$white;
            }
            $initial = false;
        }
        return $changes;
    }

    private function run(InlineFaxBits $bits, bool $white, int $maximum): int
    {
        $table = $white ? $this->codes->white : $this->codes->black;
        $length = 0;
        do {
            $this->budget->consume('instructions');
            $code = 'b';
            $run = null;
            for ($width = 1; $width <= 13; ++$width) {
                $code .= (string) $bits->read();
                if (isset($table[$code])) {
                    $run = $table[$code];
                    break;
                }
            }
            if ($run === null) {
                throw new ReadingException('CCITT contains an invalid Huffman run code.');
            }
            if ($run > $maximum - $length) {
                throw new ReadingException('CCITT run lengths exceed the declared Columns.');
            }
            $length += $run;
        } while ($run >= 64);
        return $length;
    }

    /** @return int|'pass'|'horizontal' */
    private function mode(InlineFaxBits $bits): int|string
    {
        $codes = ['1' => 0, '011' => 1, '010' => -1, '000011' => 2, '000010' => -2, '0000011' => 3, '0000010' => -3, '0001' => 'pass', '001' => 'horizontal'];
        $code = '';
        for ($width = 1; $width <= 7; ++$width) {
            $code .= (string) $bits->read();
            if (isset($codes[$code])) {
                return $codes[$code];
            }
        }
        if ($code === '0000001') {
            throw new UnsupportedInlineImageFeature('CCITT optional extension coding requires an explicit InlineImageBoundaryProvider.');
        }
        throw new ReadingException('CCITT contains an invalid two-dimensional mode.');
    }

    /** @param list<int> $changes */
    private function change(array &$changes, int $position): void
    {
        $last = array_key_last($changes);
        if ($last !== null && $changes[$last] === $position) {
            array_pop($changes);
            return;
        }
        $this->budget->consume('nodes');
        $changes[] = $position;
    }

    private function integer(DictionaryType $parameters, string $key, int $default): int
    {
        $value = $parameters->get($key);
        if ($value === null || $value instanceof NullType) {
            return $default;
        }
        if (!$value instanceof IntegerType) {
            throw new ReadingException('CCITT ' . $key . ' must be an integer.');
        }
        return $value->integer();
    }

    private function boolean(DictionaryType $parameters, string $key, bool $default): bool
    {
        $value = $parameters->get($key);
        if ($value === null || $value instanceof NullType) {
            return $default;
        }
        if (!$value instanceof BooleanType) {
            throw new ReadingException('CCITT ' . $key . ' must be boolean.');
        }
        return $value->value;
    }
}
