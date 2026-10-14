<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Reading\ByteCursor;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;
use PDF\Reading\SyntaxParser;

/** Lexical operators and operands; string bytes never serve as operator delimiters. */
final readonly class ContentInstructionReader
{
    public function __construct(private ReadingBudget $budget)
    {
    }

    /** @return \Generator<int, ContentInstruction> */
    public function read(string $bytes): \Generator
    {
        $this->budget->consume('content', strlen($bytes));
        $cursor = new ByteCursor($bytes);
        $limits = new ReaderLimits(maxDepth: $this->budget->limits->maximumDepth, maxFileBytes: max(1, strlen($bytes)), maxParsedValues: $this->budget->limits->maximumInstructions, maxRetainedBytes: $this->budget->limits->maximumContentBytes);
        $parser = new SyntaxParser($cursor, $limits, new ReadBudget($limits));
        $operands = [];
        while (true) {
            $cursor->skipWhitespaceAndComments();
            if ($cursor->eof()) {
                if ($operands !== []) {
                    throw new ReadingException('A content stream ends with unconsumed operands.');
                }
                return;
            }
            if (str_contains('/([<+-.0123456789', $cursor->peek()) || $parser->atKeyword('true') || $parser->atKeyword('false') || $parser->atKeyword('null')) {
                $this->budget->consume('instructions');
                $operands[] = $parser->read();
                continue;
            }
            $operator = '';
            while (!$cursor->eof() && !str_contains("\0\t\n\f\r ()<>[]{}/%", $cursor->peek())) {
                if (strlen($operator) >= 128) {
                    throw new ReadingException('A content operator exceeds its lexical limit.');
                }
                $operator .= $cursor->read(1);
            }
            if ($operator === '') {
                throw new ReadingException('An invalid content-stream delimiter was found.');
            }
            if ($operator === 'BI') {
                throw new ReadingException('Inline images require an explicit interpreter; ambiguous EI searching is not performed.');
            }
            $this->budget->consume('instructions');
            yield new ContentInstruction($operator, $operands);
            $operands = [];
        }
    }
}
