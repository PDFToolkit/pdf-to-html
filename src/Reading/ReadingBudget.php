<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

final class ReadingBudget
{
    /** @var list<string> */
    public array $diagnostics = [];
    /** @var array<string, int> */
    private array $used = [];
    public function __construct(public readonly ReadingLimits $limits)
    {
    }
    public function consume(string $kind, int $amount = 1): void
    {
        $maximum = match ($kind) {
            'nodes' => $this->limits->maximumNodes,
            'instructions' => $this->limits->maximumInstructions,
            'content' => $this->limits->maximumContentBytes,
            'text' => $this->limits->maximumTextBytes,
            'retainedText' => $this->limits->maximumTextBytes,
            'mappings' => $this->limits->maximumMappings,
            'assets' => $this->limits->maximumAssetsBytes,
            default => throw new \LogicException('Unknown adaptation budget.'),
        };
        $used = $this->used[$kind] ?? 0;
        if ($amount < 0 || $amount > $maximum - $used) {
            throw new ReadingException('The cumulative source ' . $kind . ' budget was exceeded.');
        }
        $this->used[$kind] = $used + $amount;
    }
    public function depth(int $depth): void
    {
        if ($depth > $this->limits->maximumDepth) {
            throw new ReadingException('The source traversal depth was exceeded.');
        }
    }
    public function diagnostic(string $message): void
    {
        $this->consume('nodes');
        $this->diagnostics[] = $message;
    }
}
