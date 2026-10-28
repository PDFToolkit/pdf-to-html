<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Reading\ByteCursor;
use PDF\Reading\Filters\CcittFaxDecoder;
use PDF\Reading\Filters\CcittFaxLimits;
use PDF\Reading\Filters\CcittFaxParameters;
use PDF\Reading\ParseException;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;
use PDF\Reading\UnsupportedPdfFeature;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NullType;

/** Reader owns the T.4/T.6 automaton; this adapter owns HTML budgets and inline parameter context. */
final readonly class InlineFaxBoundaryReader
{
    public function __construct(private ReadingBudget $budget)
    {
    }

    public function read(ByteCursor $cursor, DictionaryType $image): void
    {
        $parameters = $image->get('DecodeParms');
        $parameters = $parameters instanceof ArrayType ? ($parameters->items[0] ?? null) : $parameters;
        $parameters = $parameters instanceof DictionaryType ? $parameters : new DictionaryType();
        $endOfBlock = $this->boolean($parameters, 'EndOfBlock', true);
        $rows = $this->integer($parameters, 'Rows', 0);
        if (!$endOfBlock && $rows === 0) {
            throw new UnsupportedInlineImageFeature('CCITT without EndOfBlock requires a positive Rows count or an explicit InlineImageBoundaryProvider.');
        }
        foreach (['nodes', 'instructions', 'content'] as $kind) {
            if ($this->budget->remaining($kind) === 0) {
                $this->budget->consume($kind);
            }
        }
        $nodes = $this->budget->remaining('nodes');
        $content = $this->budget->remaining('content');
        $instructions = $this->budget->remaining('instructions');
        $limits = new ReaderLimits(
            maxFileBytes: $this->budget->limits->maximumContentBytes,
            maxDecodedStreamBytes: $content,
            maxTotalDecodedBytes: $content,
            maxRetainedBytes: min($content, $nodes > intdiv(PHP_INT_MAX, 32) ? PHP_INT_MAX : $nodes * 32),
        );
        try {
            $settings = new CcittFaxParameters(
                k: $this->integer($parameters, 'K', 0),
                columns: $this->integer($parameters, 'Columns', 1728),
                rows: $rows,
                endOfLine: $this->boolean($parameters, 'EndOfLine', false),
                encodedByteAlign: $this->boolean($parameters, 'EncodedByteAlign', false),
                endOfBlock: $endOfBlock,
                blackIs1: $this->boolean($parameters, 'BlackIs1', false),
                damagedRowsBeforeError: $this->integer($parameters, 'DamagedRowsBeforeError', 0),
            );
            $work = new CcittFaxLimits(maxBitReads: $instructions > intdiv(PHP_INT_MAX, 8) ? PHP_INT_MAX : $instructions * 8, maxRows: $nodes, maxRowTransitions: $nodes);
            $result = (new CcittFaxDecoder($limits, $settings, $work))->decodePrefix($cursor, new ReadBudget($limits));
        } catch (UnsupportedPdfFeature $error) {
            throw new UnsupportedInlineImageFeature('CCITT requires an explicit InlineImageBoundaryProvider: ' . $error->getMessage());
        } catch (ParseException $error) {
            throw new ReadingException('CCITT inline framing failed within the cumulative source nodes/content/instructions budgets: ' . $error->getMessage(), 0, $error);
        }
        $this->budget->consume('nodes', $result->rows + $result->transitions);
        $this->budget->consume('instructions', intdiv($result->bitReads, 8) + (int) ($result->bitReads % 8 !== 0) + $result->rows + $result->transitions);
        $this->budget->consume('content', strlen($result->samples) + $result->transitions * 32);
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
