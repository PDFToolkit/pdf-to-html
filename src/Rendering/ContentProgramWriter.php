<?php

declare(strict_types=1);

namespace PDF\Html\Rendering;

use PDF\Html\DerivationException;
use PDF\Html\Model\GraphicProgram;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\Object\NumericType;

/** Replay all state, but paint only selected content. Invisible text still advances text matrices. */
final readonly class ContentProgramWriter
{
    public function __construct(private GraphicsExportLimits $limits = new GraphicsExportLimits())
    {
    }

    /** @param list<array{int, int}> $ranges */
    public function write(GraphicProgram $program, array $ranges): string
    {
        if (count($program->instructions) > $this->limits->maximumInstructions) {
            throw new DerivationException('The graphics instruction export limit was exceeded.');
        }
        $context = new SerializationContext();
        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($ranges as [$first, $last]) {
            $end = count($merged) - 1;
            if ($end >= 0 && $first <= $merged[$end][1] + 1) {
                $merged[$end] = [$merged[$end][0], max($merged[$end][1], $last)];
            } else {
                $merged[] = [$first, $last];
            }
        }
        $rangeIndex = 0;
        $output = '';
        $mode = 0;
        $stack = [];
        // Complete the container, so q/Q and BT/ET remain balanced after its last selected item.
        foreach ($program->instructions as $index => $instruction) {
            while (isset($merged[$rangeIndex]) && $index > $merged[$rangeIndex][1]) {
                ++$rangeIndex;
            }
            $selected = isset($merged[$rangeIndex]) && $index >= $merged[$rangeIndex][0];
            $operator = $instruction->operator;
            if (in_array($operator, ['BMC', 'BDC', 'EMC', 'MP', 'DP'], true)) {
                continue;
            }
            if ($operator === 'q') {
                $stack[] = $mode;
            } elseif ($operator === 'Q') {
                $mode = array_pop($stack) ?? $mode;
            } elseif ($operator === 'Tr' && ($instruction->operands[0] ?? null) instanceof NumericType) {
                $mode = (int) $instruction->operands[0]->value;
            }
            if ($operator === 'BI') {
                if (!$selected) {
                    continue;
                }
                $image = $instruction->inlineImage;
                if ($image === null) {
                    throw new DerivationException('An inline graphics instruction requires its exact bytes.');
                }
                $piece = "BI\n";
                foreach ($image->dictionary->entries() as $entry) {
                    $piece .= $context->serialize($entry->key).' '.$context->serialize($entry->value)."\n";
                }
                $piece .= "ID\n".$image->bytes."\nEI\n";
            } elseif (!$selected && in_array($operator, ['S', 's', 'f', 'F', 'f*', 'B', 'B*', 'b', 'b*'], true)) {
                $piece = in_array($operator, ['s', 'b', 'b*'], true) ? "h n\n" : "n\n";
            } elseif (!$selected && in_array($operator, ['sh', 'Do'], true)) {
                continue;
            } else {
                $piece = '';
                foreach ($instruction->operands as $operand) {
                    $piece .= $context->serialize($operand).' ';
                }
                $piece .= $operator."\n";
                if (!$selected && in_array($operator, ['Tj', 'TJ', "'", '"'], true)) {
                    $piece = ($mode >= 4 ? '7' : '3')." Tr\n".$piece.$mode." Tr\n";
                }
            }
            if (strlen($piece) > $this->limits->maximumBytes - strlen($output)) {
                throw new DerivationException('The graphics content export byte limit was exceeded.');
            }
            $output .= $piece;
        }
        return $output;
    }
}
