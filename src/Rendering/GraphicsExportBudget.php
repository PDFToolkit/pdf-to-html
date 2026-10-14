<?php

declare(strict_types=1);

namespace PDF\Html\Rendering;

use PDF\Html\DerivationException;

/** Work and retained bytes accumulate across every container in one export. */
final class GraphicsExportBudget
{
    private int $nodes = 0;
    private int $bytes = 0;
    private int $instructions = 0;

    public function __construct(public readonly GraphicsExportLimits $limits)
    {
    }

    public function node(int $depth = 0): void
    {
        if (++$this->nodes > $this->limits->maximumObjects || $depth > $this->limits->maximumDepth) {
            throw new DerivationException('The cumulative graphics resource graph exceeds its export limits.');
        }
    }

    public function instructions(int $count): void
    {
        if ($count < 0 || $count > $this->limits->maximumInstructions - $this->instructions) {
            throw new DerivationException('The cumulative graphics instruction export limit was exceeded.');
        }
        $this->instructions += $count;
    }

    public function retain(int $length): void
    {
        if ($length < 0 || $length > $this->limits->maximumBytes - $this->bytes) {
            throw new DerivationException('The cumulative graphics export byte limit was exceeded.');
        }
        $this->bytes += $length;
    }
}
